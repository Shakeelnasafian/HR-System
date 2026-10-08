# Foundation backlog and acceptance plan

Status: implementation authorized and underway. The owner subsequently requested continuing remaining modules. See implementation-status.md and module-tracker.md for actual delivery and evidence; this table retains the original acceptance plan. No dates or effort estimates are promised without capacity information.

## Recommended implementation sequence

| ID | Work and requirements | Dependencies | Acceptance evidence |
|---|---|---|---|
| F00 | Close architecture decisions: independent API/SPA, reuse disposition, versions, PostgreSQL/RLS strategy, Redis license; baseline §2/20/24 | Review documents | Owner records approved decisions and residual risks; Company Tools review or explicit decision to proceed without reuse |
| F01 | Scaffold `backend/` Laravel API and `frontend/` React/TS SPA, stable lockfiles, Docker dev services and CI; §21, API-01 | F00 | Clean checkout builds; JSON API and deep SPA links work; no Inertia packages; inventory records versions/licenses |
| F02 | Fortify/Sanctum auth, invitation-only access, privileged MFA and session lifecycle; SEC-02, TEN-03 | F01 | Browser login/MFA/recovery/logout tests; CSRF rejected; revoked sessions denied; no localStorage credentials |
| F03 | Identity, tenant/company schema and scoped grants; TEN-01–03, §14 | F01 | Composite relationship constraints; distinct employee vs membership identity; two-tenant/two-company fixtures |
| F04 | Execute RLS/context spike ISO-01–15; SEC-01, API-06 | F02/F03, minimal worker/file fixtures | Actual PostgreSQL runtime role and reusable worker tests pass; record proof or revised architecture |
| F05 | Tenant/company APIs and standalone SPA shell; §13, API-01–03 | F04 | Explicit tenant selector; same-user cross-tab isolation; authorized company picker; missing/out-of-scope IDs fail closed; OpenAPI contracts match responses |
| F06 | Permission catalog/scoped assignments and grant controls; §4 | F03/F04 | Matrix cases pass; A-HR/B-self separation; revocation invalidates cached grants; no privilege escalation |
| F07 | Audit infrastructure and private file quarantine primitives; SEC-03–07, DOC-02–03 | F04/F06 | Sensitive changes/reads audited without secrets; runtime cannot edit audit; private download checks and scan-failure behavior verified |
| F08 | Worker/scheduler context, transactional outbox and delivery attempt records; API-06, NOT-01–02 | F04/F07 | Retry cannot duplicate business effects; crash between send/ack accounted for; no tenant context in another job; failed jobs alert |
| F09 | Operational baseline: health/readiness, logs, migration/recovery instructions and backup smoke test; §21 | F01/F07/F08 | Documented local run and restore rehearsal, monitored worker/scheduler failures, no publicly exposed data-service ports |
| F10 | Foundation acceptance and review | F05–09 | CI plus browser evidence attached; boundaries and remaining defects documented; owner reviews usable shell before workforce work |

F04 includes minimal synthetic file/export/job fixtures needed to prove isolation; it does not implement full HR documents or reporting. F07/F08 later harden those primitives, then rerun their relevant isolation cases. This avoids claiming a foundation is secure after testing only web controllers.

## Suggested first approval increment

Approve F00–F04 only: resolve recorded choices, scaffold the separate applications, authenticate and prove tenancy isolation. This delivers a runnable login and tenant-aware API test harness, not completed HR modules. Do not begin workforce, leave calculations, payroll or AI as incidental additions.

The implementer must use isolated task dependencies, retain delivered project dependencies and lockfiles, and remove temporary downloads/test artifacts after verification. No globally installed scaffolding tools are required by this plan.

## Later module order

| Phase | Scope | Readiness and exit checks |
|---|---|---|
| 2 Workforce | Organization, employee/employment/contracts, CSV import, transfer/rehire | Effective-date and permission schemas approved; transfer and rehire preserve history; imports dry-run/replay safely |
| 3 Approval/tasks | Sequential versioned workflows, delegation, tasks, notifications | Domain command contracts; self-approval/stale decisions blocked; missing approver exception visible |
| 4 Documents/leave | Review/expiry, leave rules, ledger, reservations, calendars | HR-approved policy inputs; concurrent requests cannot overspend; cancellation/reversal and restricted evidence tests |
| 5 Attendance/lifecycle | Overnight shifts, raw events, corrections, onboarding/probation/offboarding | Correct timezone/overnight behavior; lifecycle actions applied once; revocation not delayed by unrelated tasks |
| 6 Reports/integrations | Reconciled reports, exports, scoped tokens and webhooks | Totals reconcile, scopes hold across exports, delivery retries safe and destinations validated |
| 7 Pilot | Performance, accessibility, restore, migration rehearsal and UAT | Baseline §22 release gate, named operational owners and approved limitations |

Full endpoint schemas and state-transition contracts are required per module before it starts. The current API document establishes foundation conventions only; it is not a complete V1 OpenAPI specification.

## Test strategy and fixtures

- Backend: real PostgreSQL for constraints, RLS, effective dates and locking; separate connections for concurrency tests.
- Authorization: positive and negative cases for every matrix scope, nested resource manipulation and field-level leakage.
- Browser: API-based SPA login/MFA, deep links, tenant switching, in-flight response isolation, accessible errors and expired sessions.
- Async: real reusable worker, failure injection and retry; verify outbox/notification deduplication independently from queue locks.
- Operations: clean migration, backup/restore of database plus private files, readiness behavior and failure monitoring.

Record commands, versions, environment, assertions and results. A successful build is not proof of business correctness. The original documentation phase did not execute these tests; current evidence is tracked in implementation-status.md.
