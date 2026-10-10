<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// I3: invitations and membership lifecycle. The runtime role keeps SELECT only on tenant_memberships; every membership
// write goes through the SECURITY DEFINER functions below, which validate tenant/state themselves (PHP authorizes first).
// They run as the (NOSUPERUSER, NOBYPASSRLS) migration owner, to whom FORCE RLS still applies, so they set app.tenant_id
// transaction-locally to the validated invitation's tenant or keep the caller's current one, and restore it.
// search_path ends with pg_temp and relations are schema-qualified so a session temp table cannot shadow them.
return new class extends Migration {
    private const FUNCTIONS = ['hr_preview_invitation(uuid, uuid, text)', 'hr_accept_invitation(uuid, uuid, text, bigint, uuid)', 'hr_revoke_membership(uuid, uuid, uuid[])', 'hr_invitation_inviter_qualifies(uuid, uuid, bigint, jsonb)'];
    // I1 trigger helpers run inside the definer functions as the owner: pin pg_temp last there too.
    private const HARDENED = ['hr_permission_requires_mfa(text)', 'hr_lock_membership_mfa_policy(uuid)', 'hr_company_grants_require_mfa()', 'hr_memberships_keep_mfa_for_privileged()'];

    public function up(): void
    {
        DB::transaction(function () {
            DB::statement('ALTER TABLE tenant_memberships DROP CONSTRAINT tenant_memberships_status_check');
            DB::statement("ALTER TABLE tenant_memberships ADD CONSTRAINT tenant_memberships_status_check CHECK (status IN ('active', 'suspended', 'archived', 'revoked'))");
            DB::statement('CREATE INDEX users_email_lower_index ON users (lower(email))');
            DB::statement("CREATE TABLE invitations (
                id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES tenants (id), company_id uuid NOT NULL,
                email varchar(254) NOT NULL CHECK (email = lower(email) AND email LIKE '_%@_%'),
                permissions jsonb NOT NULL CHECK (jsonb_typeof(permissions) = 'array' AND permissions @> '[\"company.read\"]'::jsonb),
                requires_mfa boolean NOT NULL, token_hash char(64) NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
                send integer NOT NULL DEFAULT 1 CHECK (send > 0),
                status varchar(16) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'accepted', 'cancelled', 'expired')),
                expires_at timestamp(6) with time zone NOT NULL, invited_by bigint NOT NULL REFERENCES users (id),
                accepted_by bigint NULL REFERENCES users (id), accepted_at timestamp(6) with time zone NULL, cancelled_at timestamp(6) with time zone NULL,
                version integer NOT NULL DEFAULT 1 CHECK (version > 0),
                created_at timestamp(6) with time zone NOT NULL DEFAULT clock_timestamp(), updated_at timestamp(6) with time zone NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (tenant_id, id), FOREIGN KEY (tenant_id, company_id) REFERENCES companies (tenant_id, id),
                CHECK (status = 'pending' OR token_hash IS NULL), CHECK ((status = 'accepted') = (accepted_at IS NOT NULL AND accepted_by IS NOT NULL)))");
            DB::statement("CREATE UNIQUE INDEX invitations_one_pending ON invitations (tenant_id, company_id, email) WHERE status = 'pending'");
            DB::statement('ALTER TABLE invitations ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE invitations FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY tenant_boundary ON invitations USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");

            DB::unprepared(<<<'SQL'
                -- An invitation is usable only while its inviter still holds an active membership with access.manage and every
                -- invited permission in the company (re-checked at preview, send and accept). SECURITY INVOKER: callers' own RLS applies.
                CREATE FUNCTION hr_invitation_inviter_qualifies(p_tenant uuid, p_company uuid, p_inviter bigint, p_permissions jsonb) RETURNS boolean
                    LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                    SELECT EXISTS (SELECT 1 FROM public.tenant_memberships im WHERE im.tenant_id = p_tenant AND im.user_id = p_inviter AND im.status = 'active'
                        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(p_permissions || '["access.manage"]'::jsonb) AS p(code)
                            WHERE NOT EXISTS (SELECT 1 FROM public.company_grants g WHERE g.tenant_id = p_tenant AND g.membership_id = im.id
                                AND g.company_id = p_company AND g.permission = p.code)))
                $$;

                -- Public preview: zero rows for any invalid/expired/used/cancelled invitation or wrong token (indistinguishable).
                -- email is returned only to the holder of the valid secret (needed to create the invited account); the API exposes only the hint.
                CREATE FUNCTION hr_preview_invitation(p_tenant uuid, p_invitation uuid, p_token_hash text)
                    RETURNS TABLE (tenant_name text, company_name text, email_hint text, expires_at timestamptz, existing_account boolean, email text)
                    LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
                #variable_conflict use_column
                DECLARE previous text := current_setting('app.tenant_id', true); r record; found_row boolean;
                BEGIN
                    IF p_tenant IS NULL OR p_invitation IS NULL OR p_token_hash IS NULL OR p_token_hash !~ '^[0-9a-f]{64}$' THEN RETURN; END IF;
                    PERFORM set_config('app.tenant_id', p_tenant::text, true);
                    SELECT t.name AS tn, c.name AS cn, i.email AS em, i.expires_at AS ex INTO r
                        FROM public.invitations i JOIN public.tenants t ON t.id = i.tenant_id AND t.status = 'active'
                        JOIN public.companies c ON c.tenant_id = i.tenant_id AND c.id = i.company_id
                        WHERE i.tenant_id = p_tenant AND i.id = p_invitation AND i.status = 'pending'
                          AND i.expires_at > clock_timestamp() AND i.token_hash = p_token_hash
                          AND public.hr_invitation_inviter_qualifies(i.tenant_id, i.company_id, i.invited_by, i.permissions);
                    found_row := FOUND;
                    PERFORM set_config('app.tenant_id', coalesce(previous, ''), true);
                    IF NOT found_row THEN RETURN; END IF;
                    RETURN QUERY SELECT r.tn::text, r.cn::text, left(split_part(r.em, '@', 1), 1) || '***@' || split_part(r.em, '@', 2),
                        r.ex::timestamptz, EXISTS (SELECT 1 FROM public.users u WHERE lower(u.email) = r.em), r.em::text;
                END $$;

                -- Atomic acceptance. The invitation row lock serializes concurrent accepts: exactly one sees it pending.
                CREATE FUNCTION hr_accept_invitation(p_tenant uuid, p_invitation uuid, p_token_hash text, p_user bigint, p_correlation uuid)
                    RETURNS TABLE (membership_id uuid, company_id uuid, requires_mfa boolean)
                    LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
                #variable_conflict use_column
                DECLARE previous text := current_setting('app.tenant_id', true); inv record; m record; recipient text; mfa boolean;
                BEGIN
                    IF p_tenant IS NULL OR p_invitation IS NULL OR p_user IS NULL OR p_correlation IS NULL OR p_token_hash IS NULL OR p_token_hash !~ '^[0-9a-f]{64}$' THEN
                        RAISE EXCEPTION 'invitation_unavailable' USING ERRCODE = 'P0002';
                    END IF;
                    PERFORM set_config('app.tenant_id', p_tenant::text, true);
                    -- Lock order shared with grant/invitation administration and revoke: company → invitation → membership → MFA policy lock.
                    PERFORM 1 FROM public.companies c WHERE c.tenant_id = p_tenant
                        AND c.id = (SELECT i.company_id FROM public.invitations i WHERE i.tenant_id = p_tenant AND i.id = p_invitation) FOR UPDATE;
                    SELECT i.id, i.company_id, i.email, i.permissions, i.requires_mfa, i.status, i.expires_at, i.token_hash, i.invited_by INTO inv
                        FROM public.invitations i JOIN public.tenants t ON t.id = i.tenant_id AND t.status = 'active'
                        WHERE i.tenant_id = p_tenant AND i.id = p_invitation FOR UPDATE OF i;
                    -- The inviter's authority is re-validated under the company lock: a demoted/removed inviter's invitations are dead.
                    IF NOT FOUND OR inv.status <> 'pending' OR inv.expires_at <= clock_timestamp() OR inv.token_hash IS DISTINCT FROM p_token_hash
                       OR NOT public.hr_invitation_inviter_qualifies(p_tenant, inv.company_id, inv.invited_by, inv.permissions) THEN
                        RAISE EXCEPTION 'invitation_unavailable' USING ERRCODE = 'P0002';
                    END IF;
                    SELECT lower(u.email) INTO recipient FROM public.users u WHERE u.id = p_user;
                    IF recipient IS DISTINCT FROM inv.email THEN
                        RAISE EXCEPTION 'invitation_recipient_mismatch' USING ERRCODE = '42501';
                    END IF;
                    INSERT INTO public.tenant_memberships (id, tenant_id, user_id, status, requires_mfa, created_at, updated_at)
                        VALUES (gen_random_uuid(), p_tenant, p_user, 'active', inv.requires_mfa, clock_timestamp(), clock_timestamp())
                        ON CONFLICT (tenant_id, user_id) DO NOTHING;
                    SELECT tm.id, tm.status INTO m FROM public.tenant_memberships tm WHERE tm.tenant_id = p_tenant AND tm.user_id = p_user FOR UPDATE;
                    -- Suspended/archived memberships are an operator decision; an invitation must not lift them.
                    IF m.status NOT IN ('active', 'revoked') THEN
                        RAISE EXCEPTION 'invitation_membership_blocked' USING ERRCODE = '55000';
                    END IF;
                    PERFORM public.hr_lock_membership_mfa_policy(m.id); -- serializes with revoke and grant replacement for this member
                    -- A revoked membership comes back with only the invited company access.
                    IF m.status = 'revoked' THEN DELETE FROM public.company_grants g WHERE g.tenant_id = p_tenant AND g.membership_id = m.id; END IF;
                    -- MFA is only ever raised here, never lowered.
                    UPDATE public.tenant_memberships tm SET status = 'active', requires_mfa = tm.requires_mfa OR inv.requires_mfa, updated_at = clock_timestamp()
                        WHERE tm.id = m.id RETURNING tm.requires_mfa INTO mfa;
                    INSERT INTO public.company_grants (tenant_id, membership_id, company_id, permission)
                        SELECT p_tenant, m.id, inv.company_id, p FROM jsonb_array_elements_text(inv.permissions) p
                        ON CONFLICT (tenant_id, membership_id, company_id, permission) DO NOTHING;
                    UPDATE public.invitations i SET status = 'accepted', accepted_by = p_user, accepted_at = clock_timestamp(), token_hash = NULL,
                        version = i.version + 1, updated_at = clock_timestamp() WHERE i.tenant_id = p_tenant AND i.id = inv.id;
                    UPDATE public.companies c SET access_version = c.access_version + 1 WHERE c.tenant_id = p_tenant AND c.id = inv.company_id;
                    INSERT INTO public.audit_events (id, tenant_id, company_id, actor_id, action, resource_id, correlation_id, changes, reason)
                        VALUES (gen_random_uuid(), p_tenant, inv.company_id, p_user, 'invitation.accepted', inv.id, p_correlation,
                            jsonb_build_object('membership_id', m.id, 'permissions', inv.permissions, 'requires_mfa', mfa), NULL);
                    PERFORM set_config('app.tenant_id', coalesce(previous, ''), true);
                    RETURN QUERY SELECT m.id, inv.company_id, mfa;
                END $$;

                -- Removes a tenant membership in the caller's current tenant. PHP authorizes first; this re-validates that the actor
                -- administers every listed company and that the target holds grants only in those companies.
                CREATE FUNCTION hr_revoke_membership(p_actor uuid, p_target uuid, p_companies uuid[])
                    RETURNS TABLE (company_id uuid, permissions text[])
                    LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
                #variable_conflict use_column
                DECLARE tenant uuid := nullif(current_setting('app.tenant_id', true), '')::uuid; target_status text;
                BEGIN
                    IF tenant IS NULL OR p_actor IS NULL OR p_target IS NULL OR p_companies IS NULL OR p_actor = p_target THEN
                        RAISE EXCEPTION 'membership_revoke_denied' USING ERRCODE = '42501';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM public.tenant_memberships a WHERE a.tenant_id = tenant AND a.id = p_actor AND a.status = 'active')
                       OR EXISTS (SELECT 1 FROM unnest(p_companies) AS c(id) WHERE NOT EXISTS (SELECT 1 FROM public.company_grants g
                            WHERE g.tenant_id = tenant AND g.membership_id = p_actor AND g.company_id = c.id AND g.permission = 'access.manage')) THEN
                        RAISE EXCEPTION 'membership_revoke_denied' USING ERRCODE = '42501';
                    END IF;
                    PERFORM 1 FROM public.companies c WHERE c.tenant_id = tenant AND c.id = ANY (p_companies) ORDER BY c.id FOR UPDATE;
                    SELECT tm.status INTO target_status FROM public.tenant_memberships tm WHERE tm.tenant_id = tenant AND tm.id = p_target FOR UPDATE;
                    IF NOT FOUND OR target_status = 'revoked' THEN RAISE EXCEPTION 'membership_unavailable' USING ERRCODE = 'P0002'; END IF;
                    -- Grant replacement takes the same per-membership lock, so a concurrent grant in another company cannot slip past.
                    PERFORM public.hr_lock_membership_mfa_policy(p_target);
                    IF EXISTS (SELECT 1 FROM public.company_grants g WHERE g.tenant_id = tenant AND g.membership_id = p_target AND g.company_id <> ALL (p_companies)) THEN
                        RAISE EXCEPTION 'membership_grants_changed' USING ERRCODE = '55000';
                    END IF;
                    RETURN QUERY WITH removed AS (DELETE FROM public.company_grants g WHERE g.tenant_id = tenant AND g.membership_id = p_target RETURNING g.company_id, g.permission)
                        SELECT r.company_id, array_agg(r.permission::text ORDER BY r.permission) FROM removed r GROUP BY r.company_id ORDER BY r.company_id;
                    UPDATE public.tenant_memberships tm SET status = 'revoked', updated_at = clock_timestamp() WHERE tm.tenant_id = tenant AND tm.id = p_target;
                END $$;
            SQL);
            foreach (self::FUNCTIONS as $function) {
                DB::statement("REVOKE ALL ON FUNCTION $function FROM PUBLIC");
            }
            foreach (self::HARDENED as $function) {
                DB::statement("ALTER FUNCTION $function SET search_path = pg_catalog, public, pg_temp");
            }
        });
    }
    public function down(): void
    {
        foreach (self::FUNCTIONS as $function) { DB::statement("DROP FUNCTION IF EXISTS $function"); }
        DB::statement('DROP TABLE IF EXISTS invitations');
        DB::statement('DROP INDEX IF EXISTS users_email_lower_index');
        DB::statement("UPDATE tenant_memberships SET status = 'archived' WHERE status = 'revoked'");
        DB::statement('ALTER TABLE tenant_memberships DROP CONSTRAINT tenant_memberships_status_check');
        DB::statement("ALTER TABLE tenant_memberships ADD CONSTRAINT tenant_memberships_status_check CHECK (status IN ('active', 'suspended', 'archived'))");
    }
};
