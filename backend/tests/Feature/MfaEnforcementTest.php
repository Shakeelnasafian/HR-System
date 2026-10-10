<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Tenancy\CompanyAccess;
use App\Services\Tenancy\PermissionCatalog;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FoundationFixture;

class MfaEnforcementTest extends FoundationFixture
{
    private function grant(string $permission, ?string $company = null): void
    {
        DB::connection('fixture')->table('company_grants')->insert(['tenant_id' => $this->t1, 'membership_id' => $this->membership, 'company_id' => $company ?? $this->a, 'permission' => $permission]);
    }

    private function assertRejected(string $constraint, \Closure $write): void
    {
        try {
            $write();
            $this->fail("Expected $constraint to reject the write.");
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());
        }
    }

    /** Simulates an operator bypass (pg_restore/replica mode) that skips triggers, leaving a privileged grant on a non-MFA membership. */
    private function seedBypassingTriggers(array $permissions): void
    {
        DB::connection('fixture')->transaction(function ($db) use ($permissions) {
            $db->statement('SET LOCAL session_replication_role = replica');
            foreach ($permissions as $permission) {
                $db->table('company_grants')->insert(['tenant_id' => $this->t1, 'membership_id' => $this->membership, 'company_id' => $this->a, 'permission' => $permission]);
            }
        });
    }

    private function url(string $suffix, ?string $company = null): string
    {
        return '/api/v1/companies/'.($company ?? $this->a).'/'.$suffix;
    }

    public function test_database_privileged_set_matches_the_catalog(): void
    {
        foreach ([...array_keys(PermissionCatalog::LABELS), 'unknown.permission'] as $permission) {
            $this->assertSame(PermissionCatalog::requiresMfa([$permission]), DB::selectOne('select hr_permission_requires_mfa(?) as v', [$permission])->v, $permission);
        }
    }

    public function test_privileged_grant_on_membership_without_mfa_is_rejected_by_the_database(): void
    {
        $this->grant('organization.read');
        $this->assertRejected('company_grants_privileged_requires_mfa', fn () => $this->grant('workforce.write'));
        $this->assertRejected('company_grants_privileged_requires_mfa', fn () => DB::connection('fixture')->table('company_grants')
            ->where('membership_id', $this->membership)->where('permission', 'organization.read')->update(['permission' => 'access.manage']));
        $this->assertSame(['company.read', 'organization.read'], DB::connection('fixture')->table('company_grants')->where('membership_id', $this->membership)->orderBy('permission')->pluck('permission')->all());
        DB::connection('fixture')->table('tenant_memberships')->where('id', $this->membership)->update(['requires_mfa' => true]);
        $this->grant('workforce.write');
    }

    public function test_mfa_cannot_be_disabled_while_privileged_grants_exist(): void
    {
        $db = DB::connection('fixture');
        $db->table('tenant_memberships')->where('id', $this->membership)->update(['requires_mfa' => true]);
        $this->grant('workforce.read');
        $this->assertRejected('tenant_memberships_privileged_requires_mfa', fn () => $db->table('tenant_memberships')->where('id', $this->membership)->update(['requires_mfa' => false]));
        $this->assertTrue((bool) $db->table('tenant_memberships')->where('id', $this->membership)->value('requires_mfa'));
        // Other columns stay editable, and once privileged access is revoked MFA may be relaxed again.
        $db->table('tenant_memberships')->where('id', $this->membership)->update(['status' => 'active', 'updated_at' => now()]);
        $db->table('company_grants')->where('membership_id', $this->membership)->where('permission', 'workforce.read')->delete();
        $db->table('tenant_memberships')->where('id', $this->membership)->update(['requires_mfa' => false]);
        $this->assertFalse((bool) $db->table('tenant_memberships')->where('id', $this->membership)->value('requires_mfa'));
    }

    public function test_privileged_permissions_require_a_verified_session_at_use(): void
    {
        $this->seedBypassingTriggers(['organization.read', 'workforce.read', 'workforce.write']);
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);
        // Non-privileged permissions keep working for password-only sessions.
        $this->getJson('/api/v1/companies/'.$this->a)->assertOk();
        $this->getJson($this->url('organization/departments'))->assertOk();
        $this->getJson($this->url('capabilities'))->assertOk()->assertJsonPath('data', ['company.read', 'organization.read', 'workforce.read', 'workforce.write']);
        // Held privileged permissions are refused with 403 until MFA; nothing is written.
        $this->getJson($this->url('employees'))->assertForbidden()->assertJsonPath('message', 'MFA login required.');
        $this->postJson($this->url('employees'), ['employee_number' => 'P001', 'legal_name' => 'Synthetic Person', 'employment_number' => 'E001', 'start_date' => '2026-01-01'])->assertForbidden();
        $this->assertSame(0, DB::connection('fixture')->table('employees')->count());
        // Permissions the member does not hold keep 404 semantics.
        $this->getJson($this->url('audit'))->assertNotFound();
        $this->withHeader('X-Tenant-ID', $this->t2)->getJson($this->url('employees', $this->other))->assertNotFound();
        // An MFA-verified session may use them.
        $this->requireMfa()->withHeader('X-Tenant-ID', $this->t1);
        $this->postJson($this->url('employees'), ['employee_number' => 'P001', 'legal_name' => 'Synthetic Person', 'employment_number' => 'E001', 'start_date' => '2026-01-01'])->assertCreated();
        $this->getJson($this->url('employees'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_verified_session_belonging_to_another_user_or_without_enrollment_is_not_verified(): void
    {
        $this->seedBypassingTriggers(['workforce.read']);
        $this->signIn()->withSession(['mfa_user_id' => $this->uid])->withHeader('X-Tenant-ID', $this->t1);
        $this->getJson($this->url('employees'))->assertForbidden(); // session flag without enrollment
        $user = User::findOrFail($this->uid);
        $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
        auth()->forgetGuards();
        $this->actingAs($user, 'web')->withSession(['mfa_user_id' => $this->uid + 1]);
        $this->getJson($this->url('employees'))->assertForbidden();
    }

    public function test_sessionless_contexts_fail_closed_for_privileged_permissions(): void
    {
        $this->seedBypassingTriggers(['workforce.read']);
        $access = app(CompanyAccess::class);
        $context = app(TenantContext::class);
        $this->assertSame(1, $context->run($this->t1, $this->uid, fn () => $access->query('company.read')->count()));
        try {
            $context->run($this->t1, $this->uid, fn () => $access->query('workforce.read')->exists());
            $this->fail('A job context used a privileged permission without MFA.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(1, $context->run($this->t1, $this->uid, fn () => $access->query('workforce.read')->count(), true));
        $this->assertSame(0, $context->run($this->t1, $this->uid, fn () => $access->query('audit.read')->count()));
    }
}
