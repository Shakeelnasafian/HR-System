# Permission matrix proposal

Status: proposed role defaults. Permission names are illustrative stable contract candidates. No role grants legal authority by itself. All grants are restricted to the active tenant and explicitly assigned scopes.

## How to interpret the matrix

`—` = denied by default; `T` = assigned tenant administration only; `C` = assigned companies; `Team` = effective authorized reporting team; `Self` = own linked record; `Assigned` = eligible assigned task/approval; `Explicit` = separately granted and audited permission. Group HR differs from Company HR by company scope, not a bypass. Auditor and integration rights are independent grants, never inherited administrative access.

| Action | Tenant admin | Group/Company HR | Manager | Employee | Auditor |
|---|---|---|---|---|---|
| Company/settings administration | T | — | — | — | — |
| Membership invite/revoke | T | Explicit C | — | — | — |
| Role assignment | Within grant authority | — | — | — | — |
| Safe employee directory | Explicit | C | Team | Self; wider directory Explicit | Explicit |
| Employment history read | — | C | Current Team safe fields | Self permitted fields | Explicit |
| Employee/employment create/change | — | C | — | — | — |
| Transfer/termination | — | C, both sides for transfer | Request if Explicit | — | — |
| Own contact update | Self | Self | Self | Self allowlist | Self |
| Protected profile change request | Self | Self | Self | Self | Self |
| Profile-change review | — | C; no own approval | — | — | — |
| Compensation read/write | — | Explicit C, separate actions | — | Own read if Explicit | Explicit read |
| Identity-number read | — | Explicit C | — | Own if Explicit | Explicit |
| Document metadata | — | C per audience | Limited requirement status | Self permitted | Explicit |
| Document content/download | — | Explicit C per class | — | Self permitted | Explicit |
| Document upload/replace | — | C per class | — | Self permitted | — |
| Document accept/reject | — | Explicit C; not own approval | — | — | — |
| Medical evidence access | — | Explicit restricted C | — | Own permitted | Explicit |
| Leave policy administration | — | Explicit C | — | — | — |
| Leave balance/read | — | C | Team limited | Self | Explicit |
| Leave submit/withdraw/cancel request | — | On behalf if Explicit C | Self | Self | — |
| Leave approve/reject | — | Assigned | Assigned Team | — | — |
| Leave balance adjustment | — | Explicit C with reason | — | — | — |
| Attendance clock/read | — | C read | Team read; Self clock | Self | Explicit read |
| Attendance correction request | — | Explicit on behalf | Self | Self | — |
| Correction/overtime approval | — | Assigned C | Assigned Team | — | — |
| Workflow publish | — | Explicit C | — | — | — |
| Approval delegate/reassign | — | Explicit C | Assigned if delegation allowed | — | — |
| Lifecycle start/change | — | C | Explicit Team request | Own request if enabled | — |
| Task read/complete | — | C authorized | Assigned / scoped Team | Assigned | Explicit read |
| HR request/internal note | — | C; class restricted | Assigned only | Own request; no internal notes | Explicit |
| Report view | — | C | Explicit Team | Own summaries | Explicit |
| Report/export create and download | — | Explicit C | — | Own export if Explicit | Explicit |
| Audit read | Administrative events only | Explicit C | — | — | Explicit |
| Integration credential administration | T, cannot grant beyond authority | — | — | — | — |

Platform operators can provision/suspend tenants and inspect service health. They have no routine employee, document or report access. Emergency access requires an approved, expiring support grant and records both operator and subject. Integration principals have no human role or UI login; credential scopes intersect resource permissions and company scope. A token ability is never sufficient authorization by itself.

## Authorization evaluation

1. Authenticate; enforce required MFA, session status and active tenant membership (or explicit service/support authority).
2. Resolve requested tenant against that authority. Reject suspended tenant operations before domain access.
3. Resolve the resource through tenant and authorized company filters; verify each nested parent-child relationship.
4. Evaluate the exact permission and its attached scope, state, effective dates and relationship rules.
5. Apply field projection and content classification. Serialize only permitted fields; do not fetch an entire historical profile and hide fields in React.
6. Enforce self-approval and conflict rules, optimistic version and domain invariants inside the action transaction.
7. Audit sensitive access and mutation. Include actor and subject for on-behalf operations.

A frontend permission response is for UX only. Recheck server-side on every action. Implemented permission bundles are inert copy templates (see the [access contract](access-contract.md)); live role assignments do not exist yet. When an administrator assigns a role, both its permission set and scope must be within the administrator's grant authority. A role definition change must invalidate affected authorization caches. Do not use a global super-admin bypass for tenant owners.

## Critical examples for acceptance

- HR in Company A plus employee access in Company B does not imply HR in B.
- A transferred employee's new HR can see authorized current/shared fields, not old-company contracts or medical evidence by default.
- A manager sees absence dates and status without seeing diagnosis, attachments or restricted reason.
- A requester with both HR and manager roles cannot approve their own leave. Delegation cannot circumvent that rule.
- A removed member cannot download an export created earlier, use a cached role, or execute an already queued user-authorized task.
- Approver snapshots preserve history; they do not preserve revoked authority. Missing eligible approvers block the request for reassignment.
- Reporting totals, search suggestions, document names and export counts respect the same scope as details.

The final grant catalog and field-by-field payload schemas must be versioned alongside each module. This matrix is the starting policy proposal, not a claim that access enforcement exists.

## Implemented enforcement (I1, October 2026)

Every permission except `company.read` and `organization.read` is privileged and can be used only from a session that completed MFA for the current user, regardless of the membership's `requires_mfa` flag. `CompanyAccess::readable()` enforces this centrally: holding the permission without MFA verification returns 403 "MFA login required.", while not holding it still returns 404. Queue jobs and console commands have no session, so privileged checks there fail closed; a future privileged job needs an explicit design. PostgreSQL triggers (SECURITY INVOKER) reject privileged grants on memberships without `requires_mfa` and reject turning `requires_mfa` off while privileged grants exist. The privileged set is defined once in `hr_permission_requires_mfa()`, and a test keeps it equal to `PermissionCatalog`. A schema test fails if any table with `tenant_id` lacks enabled and forced RLS and a tenant policy (the bootstrap table `tenant_memberships` is the only allowlisted exception), or if the runtime role can bypass RLS, owns tables or can rewrite audit history.

## Audit and security events (I1)

Every HTTP request, artisan command and queued job has one server-generated correlation ID, stored on each audit and security event it produces and returned as the `X-Request-ID` response header. Client-supplied request IDs are ignored. `audit_events` stores microsecond database timestamps and a monotonic `seq`; the company audit list orders by `seq`, so events in one transaction keep creation order (`seq` reflects insert order, not commit order, across concurrent transactions). Authentication events (login success/failure, lockout, logout, password reset, MFA enable/confirm/disable, recovery code generation/use and challenge pass/fail) go to the global `security_events` table, because they occur before a tenant is selected. Failed logins store only an HMAC-SHA256 of the lowercased email keyed by the application key, never the attempted email, password or codes. The runtime role may only INSERT into `security_events` and only SELECT/INSERT on `audit_events`. Not yet covered: sensitive-read auditing, an API/UI for security events (owner connection only), throttled two-factor challenges, and retention/export policy for both tables.
