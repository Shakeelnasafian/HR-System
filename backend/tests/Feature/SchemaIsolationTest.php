<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Catalog-level guard: a future tenant table that forgets RLS, or a runtime role that gains ownership/bypass, fails here. */
class SchemaIsolationTest extends TestCase
{
    /** Tables with tenant_id that are deliberately not under RLS. Each entry needs a reason and a narrow runtime grant. */
    private const BOOTSTRAP = [
        // Read by TenantContext before app.tenant_id is set to resolve the membership; runtime role has SELECT only.
        'tenant_memberships',
    ];

    public function test_every_tenant_table_forces_row_level_security_with_a_tenant_policy(): void
    {
        $tables = collect(DB::select("SELECT c.relname AS name, c.relrowsecurity AS enabled, c.relforcerowsecurity AS forced,
                (SELECT count(*) FROM pg_policy p WHERE p.polrelid = c.oid AND p.polcmd = '*' AND p.polqual IS NOT NULL AND p.polwithcheck IS NOT NULL) AS policies
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') ORDER BY c.relname"))->keyBy('name');
        foreach (self::BOOTSTRAP as $table) {
            $this->assertTrue($tables->has($table), "Stale bootstrap exception: $table");
        }
        $guarded = $tables->except(self::BOOTSTRAP);
        $this->assertNotEmpty($guarded);
        foreach ($guarded as $name => $t) {
            $this->assertTrue($t->enabled, "$name must ENABLE ROW LEVEL SECURITY");
            $this->assertTrue($t->forced, "$name must FORCE ROW LEVEL SECURITY");
            $this->assertGreaterThan(0, $t->policies, "$name needs a policy with USING and WITH CHECK for all commands");
        }
    }

    public function test_runtime_role_cannot_bypass_or_rewrite_isolation(): void
    {
        $role = DB::selectOne('SELECT current_user AS name')->name;
        $this->assertNotSame(env('TEST_ADMIN_USERNAME', 'postgres'), $role, 'Tests must run as the restricted runtime role.');
        $attrs = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertFalse($attrs->rolsuper);
        $this->assertFalse($attrs->rolbypassrls);
        $this->assertSame(0, DB::selectOne("SELECT count(*) AS n FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND pg_has_role(current_user, c.relowner, 'USAGE')")->n, 'Runtime role must not own (or inherit ownership of) public relations.');
        $this->assertFalse(DB::selectOne("SELECT pg_has_role(current_user, nspowner, 'USAGE') AS v FROM pg_namespace WHERE nspname = 'public'")->v);
        foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
            $this->assertFalse(DB::selectOne('SELECT has_table_privilege(current_user, ?, ?) AS v', ['audit_events', $privilege])->v, "audit_events must be append-only ($privilege)");
        }
        foreach (['outbox_events', 'outbox_attempts'] as $table) {
            foreach (['DELETE', 'TRUNCATE'] as $privilege) {
                $this->assertFalse(DB::selectOne('SELECT has_table_privilege(current_user, ?, ?) AS v', [$table, $privilege])->v, "$table history must not be deletable at runtime ($privilege)");
            }
        }
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            $this->assertFalse(DB::selectOne('SELECT has_table_privilege(current_user, ?, ?) AS v', ['tenant_memberships', $privilege])->v, "tenant_memberships must stay read-only at runtime ($privilege)");
        }
    }
}
