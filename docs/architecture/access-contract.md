# Company permission administration

This increment reuses direct company grants and the existing transaction, MFA, tenant context and audit infrastructure. It adds no package or role inheritance. Invitation acceptance, tenant membership lifecycle and reusable role bundles remain separate work.

Both routes require the session, `X-Tenant-ID`, an active tenant/membership, `access.manage` for the route company and confirmed MFA in the current session. PUT also requires CSRF. Unknown or unauthorized company/member references return 404.

- `GET /api/v1/companies/{company}/access?page=1&per_page=25`: paginated existing company members with id, display name, email, membership status, MFA requirement and exact grants. Additional fields: `access_version`, `actor_membership_id`, and code-defined `catalog` with labels/delegability. No unrelated tenant members or employee profiles are listed.
- `PUT /api/v1/companies/{company}/access/{membership}`: `{version: integer, reason: string(max 500), permissions: string[]}`. Replaces the target's company permissions; returns membership ID, sorted grants and the new access version. Empty permissions removes all company access. Missing, unknown and duplicate entries fail validation. Nonempty lists must contain `company.read`.

The administrator cannot edit their own grants. Both added and removed permissions must be within their current authority in that same company. Unheld existing grants must be preserved. The company row is locked and actor authority rechecked before mutation. Every successful change increments the company-wide version, so stale simultaneous edits return 409 and must be reviewed again. A no-op does not create another audit event or increment the version.

Privileged grants require the target membership to enforce MFA; the API cannot lower that setting. Inactive memberships cannot be changed. Permission administration itself always requires MFA even if the actor membership flag was misconfigured. Another authorized administrator must make changes to the actor, so this API cannot remove its own last administrator.

Changes and redacted permission-name differences are audited in the same transaction. Authorization uses current database grants without caching. Removing company access makes the target disappear from this scoped membership list; an operator or the future invitation/provisioning workflow must explicitly add them back. This API intentionally manages existing company membership only, and cannot discover or attach arbitrary accounts from other companies/tenants.

Grant, revoke, self-change, escalation, stale version, MFA, unknown ID, cross-company/cross-tenant cases are covered by integration tests. Browser tests exercise an authorized permission change through the SPA. Names/emails are administrative identity metadata for members already linked to this company; private HR fields are never loaded by these routes.
