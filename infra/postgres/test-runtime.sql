-- CI only: synthetic data, no production credentials.
CREATE ROLE hr_app LOGIN PASSWORD 'test-only-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE hr_test TO hr_app;
GRANT USAGE ON SCHEMA public TO hr_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON users, sessions, password_reset_tokens, cache, cache_locks, jobs, job_batches, failed_jobs TO hr_app;
GRANT SELECT ON tenants, tenant_memberships TO hr_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON companies, company_grants, departments, locations, positions, employees, employments TO hr_app;
GRANT SELECT, INSERT, UPDATE ON permission_bundles TO hr_app;
GRANT SELECT, INSERT ON audit_events TO hr_app;
GRANT SELECT, INSERT, UPDATE ON outbox_events, outbox_attempts TO hr_app;
GRANT SELECT ON invitations TO hr_app;
GRANT INSERT (id, tenant_id, company_id, email, permissions, requires_mfa, expires_at, invited_by) ON invitations TO hr_app;
GRANT UPDATE (status, token_hash, send, expires_at, version, cancelled_at, updated_at) ON invitations TO hr_app;
GRANT EXECUTE ON FUNCTION hr_preview_invitation(uuid, uuid, text), hr_accept_invitation(uuid, uuid, text, bigint, uuid), hr_revoke_membership(uuid, uuid, uuid[]), hr_invitation_inviter_qualifies(uuid, uuid, bigint, jsonb) TO hr_app;
GRANT INSERT ON security_events TO hr_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO hr_app;
