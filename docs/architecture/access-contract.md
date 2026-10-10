# Company permission administration

This increment reuses direct company grants and the existing transaction, MFA, tenant context and audit infrastructure. It adds no package or role inheritance. Invitation acceptance, tenant membership lifecycle and live role assignments remain separate work.

Both routes require the session, `X-Tenant-ID`, an active tenant/membership, `access.manage` for the route company and confirmed MFA in the current session. PUT also requires CSRF. Unknown or unauthorized company/member references return 404.

- `GET /api/v1/companies/{company}/access?page=1&per_page=25`: paginated existing company members with id, display name, email, membership status, MFA requirement and exact grants. Additional fields: `access_version`, `actor_membership_id`, and code-defined `catalog` with labels/delegability. No unrelated tenant members or employee profiles are listed.
- `PUT /api/v1/companies/{company}/access/{membership}`: `{version: integer, reason: string(max 500), permissions: string[]}`. Replaces the target's company permissions; returns membership ID, sorted grants and the new access version. Empty permissions removes all company access. Missing, unknown and duplicate entries fail validation. Nonempty lists must contain `company.read`.

The administrator cannot edit their own grants. Both added and removed permissions must be within their current authority in that same company. Unheld existing grants must be preserved. The company row is locked and actor authority rechecked before mutation. Every successful change increments the company-wide version, so stale simultaneous edits return 409 and must be reviewed again. A no-op does not create another audit event or increment the version.

Privileged grants require the target membership to enforce MFA; the API cannot lower that setting. Inactive memberships cannot be changed. Permission administration itself always requires MFA even if the actor membership flag was misconfigured. Another authorized administrator must make changes to the actor, so this API cannot remove its own last administrator.

Changes and redacted permission-name differences are audited in the same transaction. Authorization uses current database grants without caching. Removing company access makes the target disappear from this scoped membership list; an operator or the future invitation/provisioning workflow must explicitly add them back. This API intentionally manages existing company membership only, and cannot discover or attach arbitrary accounts from other companies/tenants.

Grant, revoke, self-change, escalation, stale version, MFA, unknown ID, cross-company/cross-tenant cases are covered by integration tests. Browser tests exercise an authorized permission change through the SPA. Names/emails are administrative identity metadata for members already linked to this company; private HR fields are never loaded by these routes.

## Reusable permission bundles

Bundles are immutable company-scoped templates, not live role assignments. Creating or archiving a template does not change any member permissions. The SPA copies the selected template into the existing permission editor additively, preserving grants outside the actor’s authority. The existing preview, company version, current-authority recheck, target MFA policy and audit apply at save time. A copied template is a draft selection: archiving it later does not invalidate that selection or revoke access. No persistent bundle/member relationship is claimed.

All routes require the same access.manage, MFA and tenant controls as permission administration:

- `GET /api/v1/companies/{company}/permission-bundles`: active templates with id, name, permission codes and current actor delegability.
- `POST /api/v1/companies/{company}/permission-bundles`: name (100 characters), nonempty known distinct permissions including company.read, and reason (500 characters). Returns 201. Entire template must be within actor’s current company permissions. Names remain reserved after archive; use a new name for a revision. At most 100 active templates per company.
- `POST /api/v1/companies/{company}/permission-bundles/{bundle}/archive`: reason required; idempotent retirement, requiring authority over every template permission. No edit/delete endpoint.

Creation and archiving lock the same company row as grant administration and recheck current authority, preventing stale delegation races. Tenant RLS is forced, the company foreign key includes tenant ID, and runtime privileges exclude DELETE. Audit events contain permission codes and resource IDs, not bundle names. No global users or membership write privileges were added.
