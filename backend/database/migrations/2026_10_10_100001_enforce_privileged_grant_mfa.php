<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Defence in depth for operator/SQL seeding: a privileged grant can only exist on a membership that requires MFA.
// The privileged set lives in hr_permission_requires_mfa() and must equal PermissionCatalog::requiresMfa() (MfaEnforcementTest).
// Every function is SECURITY INVOKER: the inserting/updating role needs its own rights on these rows anyway.
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION hr_permission_requires_mfa(permission text) RETURNS boolean
                    LANGUAGE sql IMMUTABLE STRICT SECURITY INVOKER SET search_path = pg_catalog
                    AS $$ SELECT permission NOT IN ('company.read', 'organization.read') $$;
            SQL);
            // The non-superuser owner is exempt from RLS only while FORCE is lifted, so count every tenant's rows inside this transaction.
            DB::statement('ALTER TABLE company_grants NO FORCE ROW LEVEL SECURITY');
            $violations = DB::selectOne("SELECT count(*) AS n FROM company_grants g JOIN tenant_memberships m ON m.tenant_id = g.tenant_id AND m.id = g.membership_id
                WHERE hr_permission_requires_mfa(g.permission) AND m.requires_mfa IS NOT TRUE")->n;
            DB::statement('ALTER TABLE company_grants FORCE ROW LEVEL SECURITY');
            if ($violations > 0) {
                throw new RuntimeException("$violations privileged company grant(s) belong to memberships without requires_mfa. Require MFA on those memberships or revoke the grants, then rerun.");
            }
            DB::unprepared(<<<'SQL'
                -- Both triggers take the same per-membership transaction lock so a grant insert and an MFA downgrade cannot race past each other.
                CREATE FUNCTION hr_lock_membership_mfa_policy(membership uuid) RETURNS void
                    LANGUAGE sql VOLATILE STRICT SECURITY INVOKER SET search_path = pg_catalog
                    AS $$ SELECT pg_advisory_xact_lock(hashtextextended('hr.membership_mfa:' || membership::text, 0)) $$;

                CREATE FUNCTION hr_company_grants_require_mfa() RETURNS trigger
                    LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public AS $$
                BEGIN
                    IF hr_permission_requires_mfa(NEW.permission) THEN
                        PERFORM hr_lock_membership_mfa_policy(NEW.membership_id);
                        -- tenant_memberships has no RLS (bootstrap table); hr_app holds SELECT on it.
                        IF NOT EXISTS (SELECT 1 FROM tenant_memberships m WHERE m.tenant_id = NEW.tenant_id AND m.id = NEW.membership_id AND m.requires_mfa) THEN
                            RAISE EXCEPTION 'company_grants_privileged_requires_mfa: privileged permission % requires a membership with requires_mfa = true', NEW.permission
                                USING ERRCODE = 'check_violation', CONSTRAINT = 'company_grants_privileged_requires_mfa';
                        END IF;
                    END IF;
                    RETURN NEW;
                END $$;
                CREATE TRIGGER company_grants_privileged_requires_mfa BEFORE INSERT OR UPDATE OF permission, membership_id, tenant_id ON company_grants
                    FOR EACH ROW EXECUTE FUNCTION hr_company_grants_require_mfa();

                CREATE FUNCTION hr_memberships_keep_mfa_for_privileged() RETURNS trigger
                    LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public AS $$
                DECLARE previous text := current_setting('app.tenant_id', true); held boolean;
                BEGIN
                    PERFORM hr_lock_membership_mfa_policy(NEW.id);
                    -- company_grants has FORCE RLS: scope the lookup to this row's tenant so an owner role without a tenant
                    -- context cannot see zero rows and pass silently. The previous setting is restored immediately.
                    PERFORM set_config('app.tenant_id', NEW.tenant_id::text, true);
                    SELECT EXISTS (SELECT 1 FROM company_grants g WHERE g.tenant_id = NEW.tenant_id AND g.membership_id = NEW.id
                        AND hr_permission_requires_mfa(g.permission)) INTO held;
                    PERFORM set_config('app.tenant_id', coalesce(previous, ''), true);
                    IF held THEN
                        RAISE EXCEPTION 'tenant_memberships_privileged_requires_mfa: membership % holds privileged grants; revoke them before disabling requires_mfa', NEW.id
                            USING ERRCODE = 'check_violation', CONSTRAINT = 'tenant_memberships_privileged_requires_mfa';
                    END IF;
                    RETURN NEW;
                END $$;
                CREATE TRIGGER tenant_memberships_privileged_requires_mfa BEFORE UPDATE OF requires_mfa ON tenant_memberships
                    FOR EACH ROW WHEN (NEW.requires_mfa IS NOT TRUE)
                    EXECUTE FUNCTION hr_memberships_keep_mfa_for_privileged();
            SQL);
        });
    }
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS tenant_memberships_privileged_requires_mfa ON tenant_memberships;
            DROP TRIGGER IF EXISTS company_grants_privileged_requires_mfa ON company_grants;
            DROP FUNCTION IF EXISTS hr_memberships_keep_mfa_for_privileged();
            DROP FUNCTION IF EXISTS hr_company_grants_require_mfa();
            DROP FUNCTION IF EXISTS hr_lock_membership_mfa_policy(uuid);
            DROP FUNCTION IF EXISTS hr_permission_requires_mfa(text);
        SQL);
    }
};
