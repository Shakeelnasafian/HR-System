-- CI only: synthetic data, no production credentials.
CREATE ROLE hr_app LOGIN PASSWORD 'test-only-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE hr_test TO hr_app;
GRANT USAGE ON SCHEMA public TO hr_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON users, sessions, password_reset_tokens, cache, cache_locks, jobs, job_batches, failed_jobs TO hr_app;
GRANT SELECT ON tenants, tenant_memberships TO hr_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON companies, company_grants, departments, locations, positions, employees, employments TO hr_app;
GRANT SELECT, INSERT, UPDATE ON permission_bundles, working_calendars TO hr_app;
GRANT SELECT, INSERT ON calendar_patterns TO hr_app;
GRANT SELECT, INSERT, DELETE ON calendar_holidays TO hr_app;
GRANT SELECT, INSERT ON audit_events TO hr_app;
GRANT SELECT, INSERT, UPDATE ON outbox_events, outbox_attempts TO hr_app;
GRANT SELECT, INSERT, UPDATE ON invitations TO hr_app;
GRANT EXECUTE ON FUNCTION hr_preview_invitation(uuid, uuid, text), hr_accept_invitation(uuid, uuid, text, bigint, uuid), hr_revoke_membership(uuid, uuid, uuid[]) TO hr_app;
GRANT INSERT ON security_events TO hr_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO hr_app;
