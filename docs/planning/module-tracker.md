# V1 module delivery tracker

User direction: continue the remaining modules, preserving a separate API-based SPA with no Inertia. This tracker distinguishes usable increments from complete V1 modules. No production release is claimed.

| Module | Delivered increment | Remaining obligations |
|---|---|---|
| Identity/tenancy | Cookie login, MFA, tenant selector, company read/action scopes, scoped grant administration with delegation limits, restricted runtime RLS and worker context | Invitations, membership lifecycle, reusable scoped role bundles, recovery/session lifecycle expansion |
| Organization | Departments, locations, positions; create/rename/archive/version checks and same-company references | Company administration, calendars/holidays, settings origin/effective dates |
| Workforce | Safe identity/directory, draft employment, activate/end/cancel, rehire/history, overlap protection | Full private profiles, self-service changes, manager/effective assignments, terms/contracts, reconciled transfer, CSV dry-run/import |
| Audit | Transactional organization/workforce mutations, protected append-only table, company viewer | Auth/admin/sensitive reads, export coverage, retention/legal holds and support access |
| Approval/tasks | Not implemented | Sequential immutable workflow versions, eligible approvers, self-approval blocks, delegation, stale decisions, blocked routes and tasks |
| Private files/documents | Not implemented | Ownership/audiences, quarantine scanner, private download checks, review/versioning, expiry/reminder tasks |
| Leave | Not implemented | HR-approved policy inputs, schedules, immutable ledger/reservations, concurrent balances, approvals/cancellations/reversal |
| Attendance | Not implemented | Shifts/overnight timezones, raw events/deduplication, correction/overtime approvals |
| Lifecycle | Not implemented | Versioned onboarding/probation/offboarding templates, tasks, idempotent domain actions |
| Notifications/outbox | Not implemented | Transactional outbox, delivery attempts, deduplication, revoked-recipient checks, alerts |
| Reports/integrations | Not implemented | Reconciled scoped reports, audited expiring exports, credential scopes, signed/retried webhooks |
| Operations/pilot | Docker local stack and CI | Full readiness/monitoring, private data backups/restore, load/accessibility/security/UAT and migration rehearsal |

## Next implementation order

1. Complete authorization administration and outbox/private-file foundations, including the outstanding isolation experiments.
2. Complete workforce effective dates/contracts/imports; add reconciled transfers when leave/workflow/file ownership can participate.
3. Sequential approvals, tasks and notifications, then document review/expiry and leave ledger policies.
4. Attendance and lifecycle, then reports/integrations and production pilot gates.

Leave entitlements, working calendars, transfer balance treatment and retention require actual company policy inputs. The code does not invent statutory defaults, calculate payroll or expose placeholder modules as finished functionality.
