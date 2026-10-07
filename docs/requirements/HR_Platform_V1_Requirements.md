# HR Platform — V1 Requirements and Delivery Baseline

Version: 1.0 · Date: 7 October 2026 · Status: Draft for review

This document defines the proposed first production release and the longer-term product direction. It authorizes no application implementation. Requirements marked **MUST** are V1 release obligations; **SHOULD** indicates a preference that may be revised during review; later-phase items are explicitly excluded from V1. Proposed defaults and unresolved business decisions are identified separately from confirmed direction.

## 1. Goal and success criteria

Create a multi-tenant HR platform that supports multiple companies inside each tenant. HR administrators, managers and employees should complete everyday workforce processes in one system, with clear ownership, reliable approvals and controlled access to sensitive information.

V1 succeeds when a pilot tenant with at least two companies can maintain employment history, collect and review documents, track expiry, request and approve leave, record and correct attendance, run onboarding/probation/offboarding tasks, and report on its workforce without cross-tenant or unauthorized cross-company disclosure. Every sensitive mutation must be attributable, and records must remain consistent during concurrent requests, retries and company transfers.

The product must be operable after deployment: monitored queues, backups, restoration procedures, support tools and a documented release process are part of delivery.

## 2. Confirmed direction and proposed decisions

| Item | Baseline | Status |
|---|---|---|
| Ownership hierarchy | Platform → Tenant → Companies → Locations/departments | Confirmed direction |
| Tenant | Security and data ownership boundary; may represent a group | Confirmed direction |
| Employee | Person identity inside one tenant | Confirmed direction |
| Employment | Effective-dated relationship between employee and company | Confirmed direction |
| User access | One or multiple company scopes; separate from employment | Confirmed direction |
| Backend | Laravel modular monolith | Agreed planning baseline; exact version pending |
| Frontend | React and TypeScript | Agreed planning baseline |
| Persistence | Shared PostgreSQL with tenant-owned records | Proposed initial deployment |
| Async processing | Redis queues and scheduled workers | Agreed planning baseline |
| Deployment | Docker; web, application, workers, scheduler and supporting services | Agreed planning baseline |
| Isolation controls | Application authorization plus PostgreSQL row-level security as defense in depth | Proposed; validate in architecture spike |
| Authentication | First-party session authentication; privileged-role MFA | Proposed |
| Simultaneous employment | Schema supports it; V1 UI prevents overlapping active employments unless explicitly enabled | Proposed scope limit |
| Company meaning | Company is the employing legal entity; locations/branches sit beneath it | Proposed simplification |

Do not add a second mandatory “legal entity” layer beneath company unless the business needs operating brands distinct from employers. Do not treat roles as a privilege inheritance chain: each role is a permission bundle with explicit scope.

The specification is based on the supplied conversation, not an inspection of an existing repository. Framework/package versions, licensing and maintained alternatives have not yet been evaluated. Before scaffolding, inspect any existing Company Tools code and compare reuse, suitable HR products and a custom build against these requirements. Custom development remains a direction to validate, not proof that existing products cannot meet the need.

## 3. Scope

### V1 included

Tenant/company administration; organization and employees; contracts and employment history; documents and expiry; leave; attendance and shifts; bounded configurable approvals and lifecycle templates; tasks and notifications; employee, manager and HR workspaces; audit and reporting; import/export; limited integration API and outbound webhooks; production operations.

### Later releases

Payroll calculation, WPS/bank files, gratuity/final-settlement calculation, recruitment/ATS, performance/360 reviews, LMS, expense/travel claims, full asset inventory, workforce budgeting, succession planning, AI Copilot, SaaS subscriptions, marketplace integrations, biometric vendor connectors, native mobile applications and offline attendance.

V1 offboarding may record an externally approved settlement and asset-return tasks. It must not pretend to calculate payroll or gratuity. V1 is not a general-purpose visual automation platform: workflow configuration is limited to supported HR triggers, conditions and actions.

## 4. Roles and authorization

| Role | Permitted baseline | Restrictions |
|---|---|---|
| Platform operator | Tenant provisioning, service health, account recovery processes | No routine access to employee records |
| Tenant owner/admin | Company setup, membership, approved role assignment, tenant settings | Sensitive HR access requires explicit permissions |
| Group HR | HR operations across assigned companies | No access to other tenants |
| Company HR | HR operations inside assigned companies | No unrestricted access to shared employee histories |
| Manager | Current scoped team, leave/attendance approvals, assigned lifecycle tasks | No compensation, identifiers or medical attachments by default |
| Employee | Own permitted profile, documents, requests, attendance and tasks | Cannot change employment terms or approve own requests |
| Auditor | Read permitted historical events and records | No operational writes; confidential fields still scoped |
| Integration identity | Explicit API resources and company scopes | No UI login or implicit administrative powers |

MUST:

- Model permissions as resource/action plus tenant, company, department, current reporting team or self scope.
- Authorize every API, download, search, dashboard, export, background job and webhook independently.
- Separate viewing documents from viewing metadata; viewing compensation from viewing employee directory fields; exporting from ordinary read access.
- Prevent self-approval. If no eligible approver exists, hold the request and alert HR; never automatically approve it.
- Resolve reporting relationships by effective dates and reject cycles.
- Recheck permissions after role removal and session revocation. A selected company in the UI is a filter, not a security control.
- Restrict role assignment so administrators cannot grant privileges beyond their own grant authority.
- Record time-limited emergency support access, reason, approval, scope and expiry. Visible impersonation must preserve both actor and subject identity.
- Support users in multiple tenants with explicit active-tenant selection. Roles and employee identities must not carry across tenants.

## 5. Tenant and company administration

**TEN-01:** Provision, activate, suspend and archive tenants. Suspension blocks new tenant activity and pauses business automations; platform recovery remains possible. Tenant deletion is a separate controlled retention process.

**TEN-02:** Manage companies, locations, departments, positions, working calendars, holidays and timezones. A department belongs to a company. Tenant-level reusable definitions may be assigned to companies, but ownership must remain explicit.

**TEN-03:** Invite users, expire invitations, remove memberships, assign scoped roles and link a membership to an employee identity when applicable. Employment termination must not silently remove unrelated authorized memberships.

**TEN-04:** Settings include locale, date display, currency display, document requirements, reminder schedules, leave rules and attendance rules. Resolve settings through tenant defaults and explicit company overrides; display their origin. Policy changes require effective dates and audit history.

**TEN-05:** Archive referenced organizations rather than deleting them. Historical employments retain their original department, company and position context.

Acceptance: a Company A administrator cannot retrieve Company B employment details through guessed identifiers, search, exports or nested associations; suspended tenant sessions cannot continue ordinary writes.

## 6. Employee, organization and employment core

**EMP-01:** Maintain tenant employee number, preferred/legal names, contact details, birth date, nationality, address and emergency contacts. Collect only configured necessary fields. Employee number is unique within tenant; email is not a universal identity key.

**EMP-02:** Maintain employment number, company, location, department, position, manager, employment type, start/end dates, working calendar, probation dates and employment status. Employment number is company-scoped. Store business dates separately from timestamps.

**EMP-03:** Store effective-dated changes to reporting line, department, position, employment terms and optional restricted compensation metadata. Contracts have versions, dates, attachments and lifecycle state. V1 stores terms; it does not run payroll.

**EMP-04:** Support draft creation, activation, termination and rehire. Duplicate warnings must not automatically merge people. An authorized merge process must preserve references and a traceable history if introduced.

**EMP-05:** Transfer with an effective date, close the previous relationship and create the destination employment. Explicitly reconcile pending approvals, policy assignments, leave balances, tasks and document access. Preview the transfer before confirmation; do not copy entitlements silently.

**EMP-06:** Directory, filtered employee list, employment timeline and org chart. Directory exposure uses a separate safe field set. Users can search by authorized employee number, name, department and employment status.

**EMP-07:** Allow employees to submit changes to protected profile fields for HR review; simple permitted contact changes can be immediate and audited.

Acceptance: transferring an employee preserves prior contracts, leave transactions and historical reports; destination HR sees only authorized current and shared fields. Rehire adds a new relationship without recreating the person.

## 7. Documents and compliance tracking

**DOC-01:** Configure document types: passport, Emirates ID, visa, insurance, contract and certification, plus custom types. Rules include required audience, owner context, expiry requirement, review requirement, reminder offsets and confidentiality class.

**DOC-02:** Upload versions with issue/expiry dates and metadata. Ownership is explicitly tenant-person, employment or company. A new company relationship does not automatically expose all historical attachments.

**DOC-03:** Quarantine uploads until validation/security scanning completes. Enforce allowed types and size, inspect content type, prohibit executable content and make downloads private. Scan failure must not publish the file.

**DOC-04:** Authorized reviewers accept/reject with reason; employees can replace rejected documents. Review status and expiry are distinct: an approved document may later expire.

**DOC-05:** Daily expiry processing generates deduplicated in-app/email reminders and renewal tasks. Replacement, changed expiry or employment ending must update outstanding reminders consistently.

**DOC-06:** Provide missing/expiring/expired reports and scoped renewal queues. Notifications must avoid sensitive identifiers or medical contents.

Acceptance: unauthorized direct file access fails; rejected versions remain traceable; retries do not generate duplicate renewal tasks; archived document versions do not masquerade as current.

## 8. Leave management

**LEV-01:** Define leave types and effective-dated policies, including eligibility, unit, accrual frequency, annual allocation, carry-forward, expiry, caps, negative-balance allowance, attachments and approval routing. Units are explicit; no ambiguous conversion between days and hours.

**LEV-02:** Assign policies to employment, with location calendar and work schedule. Support annual, sick, unpaid and custom types without claiming preset legal compliance.

**LEV-03:** Maintain an append-only balance ledger for allocations, accruals, opening balances, adjustments, reservations, consumption, reversals, carry-forward and expiry. Display allocated, used, reserved and available amounts.

**LEV-04:** Preview duration using working calendar, holidays and schedule; support full and half days. Hourly leave is deferred unless approved during review. Preserve the calculation snapshot used for the request.

**LEV-05:** Submit, withdraw, approve, reject and request cancellation. Pending requests reserve entitlement under the proposed default. Approval converts reservation to consumption; rejection/withdrawal releases it. Cancellation of approved leave requires approval and a reversing ledger entry.

**LEV-06:** Prevent duplicate/overlapping active leave, insufficient entitlement and requests outside eligible employment dates. Concurrent submissions must not overspend balances. Handle schedule changes and holidays added after approval through explicit recalculation review.

**LEV-07:** Team calendar exposes absence status and dates, not medical diagnosis or confidential attachments. Authorized HR can adjust balances with reason and evidence.

Acceptance: repeated accrual jobs post once per policy period; simultaneous leave submissions cannot exceed entitlement; cancellation restores exactly the relevant balance; transfer settlement is explicit.

## 9. Attendance and shifts

**ATT-01:** Manage shift templates, break rules, assignments, weekends, grace periods and company/location timezone. Prevent overlapping shift assignments; support overnight shifts and historical effective dates.

**ATT-02:** Record server-timestamped web clock-in/out events. Preserve raw events separately from derived daily attendance. Flag missing, duplicate, late, early and unmatched events for review.

**ATT-03:** Display attendance totals, expected hours and exception status. Distinguish worked time, scheduled time, approved overtime and leave; do not infer disciplinary conclusions.

**ATT-04:** Employee submits correction with original/proposed times and reason. Approval creates an adjustment while preserving original events. Recalculate affected attendance after approved correction.

**ATT-05:** Manager/HR reviews overtime as time only; no pay calculation. Employee and HR views show the source and approval state.

**ATT-06:** Import device events through scoped API/import later in V1 integration work. Require external identifiers and replay deduplication. Vendor-specific adapters remain deferred.

Acceptance: a 22:00–06:00 shift produces one correct shift record; clock retries are deduplicated; corrections retain original values; a missed clock-out is flagged rather than fabricated. Any IP/location restrictions are optional policies; geolocation and biometrics are not V1 defaults.

## 10. Lifecycle workflows

**LIF-01:** Template-based onboarding assigns HR, employee, manager and IT tasks, required documents, due dates relative to start date and completion evidence.

**LIF-02:** Probation tracking records review due date, reviewer, outcome and approved extension. Reminders escalate overdue reviews. No automatic confirmation or termination.

**LIF-03:** Promotion and transfer requests capture proposed effective-dated changes and approval evidence. Changes apply once after approval.

**LIF-04:** Offboarding records resignation/termination type, notice dates, last working date, approval and restricted reason. Generate handover, asset return, access revocation, document and external settlement tasks.

**LIF-05:** Completion must distinguish required blocking tasks from informational tasks. Outstanding items remain visible; an authorized override requires a reason. Access revocation must not wait indefinitely for unrelated settlement tasks.

**LIF-06:** Cancellation or rescheduling adjusts future tasks without erasing completed evidence. Employment end does not delete the employee or audit history.

Acceptance: completing a lifecycle instance twice cannot duplicate employment mutations; overdue tasks escalate to configured people; changing manager does not orphan tasks or approvals.

## 11. Approval and workflow infrastructure

**WF-01:** Provide approved trigger types: request submitted, employment activated/changed/ended, probation due, document expiry approaching and task overdue.

**WF-02:** V1 conditions use controlled fields such as company, department, employment type, leave type and amount. No arbitrary scripts, SQL, HTTP actions or unrestricted expressions.

**WF-03:** Support ordered approval steps, explicit user/role/line-manager resolution, required decision counts and optional nonblocking tasks. Parallel approval and complex branching are deferred unless a concrete V1 workflow needs them.

**WF-04:** Publishing a template creates an immutable version. Submitted instances retain policy, workflow and approver snapshots. Changes do not silently rewrite running requests.

**WF-05:** Support approve/reject, controlled delegation, reassignment, escalation and cancellation with reasons. Delegates must be eligible within the same tenant and required scope. Notify on reassignment and audit every transition.

**WF-06:** Handle missing/inactive approvers through a visible HR exception queue. Do not treat escalation timeout as approval. Recheck actor authority and self-approval restrictions at decision time.

**WF-07:** Apply the approved domain change transactionally and once. Use explicit transition validation and optimistic version checks for stale decisions; reject duplicate conflicting actions.

## 12. Tasks, notifications and requests

**TSK-01:** Tasks have tenant, relevant company/employment, assignee, source, status, priority, due date, evidence and completion timestamp. Confidential task contents inherit restricted visibility.

**TSK-02:** Provide personal and team queues, filters, reassignment and overdue escalation. Lifecycle task templates cover IT/asset checklist needs without a full asset module.

**NOT-01:** In-app notification inbox plus email. Queue delivery, track attempts and failure, deduplicate using business event/recipient/channel keys and support bounded retries. Do not promise exactly-once delivery to external systems.

**NOT-02:** Essential approval/security notifications cannot be disabled; optional reminders can follow preference rules. Emails contain safe summaries and authenticated links, not confidential attachments.

**REQ-01:** Include a small HR request queue for profile correction, document renewal and configurable administrative requests. Owner, status, attachments, internal notes and response history are required. A full confidential grievance/SLA service desk is deferred.

## 13. Workspaces and UX

| Workspace | Required screens |
|---|---|
| Employee | Home, own profile, employments, permitted documents, leave balance/request/calendar, attendance/corrections, tasks, HR requests, notifications |
| Manager | Team directory, current attendance/absence, approvals, team calendar, probation tasks, scoped lifecycle tasks |
| HR | Workforce list/profile/history, organization, imports, document compliance, leave policies/ledger, attendance exceptions, lifecycle instances, workflow configuration, reports, audit |
| Tenant admin | Companies, memberships, scoped permissions, settings, integrations |
| Platform operator | Tenant service state, provisioning and support access records |

MUST: responsive desktop/mobile web; clear active tenant/company; keyboard navigation; labelled fields; accessible error and focus behavior; useful loading/empty/error states; server-side pagination; saved filter options where valuable; accessible status indicators that do not rely only on color. Target WCAG 2.2 AA and verify applicable criteria during UX acceptance.

Confirm consequential actions with a concrete preview. Preserve form input after validation errors. Hide unauthorized controls but also enforce server authorization. Company “all” views are available only within granted scopes. Current V1 interface language is proposed as English; internationalization infrastructure should permit later Arabic/RTL without claiming that translation is included.

## 14. Conceptual data model

This is a requirements-level model, not final migrations.

| Area | Principal entities |
|---|---|
| Identity | users, tenants, tenant_memberships, invitations, sessions |
| Authorization | roles, permissions, role_assignments, company_access, support_access_grants |
| Organization | companies, locations, departments, positions, calendars, holidays |
| Workforce | employees, employments, employment_assignments, employment_terms, contracts, profile_change_requests |
| Documents | document_types, documents, document_versions, document_reviews |
| Leave | leave_types, policy_versions, policy_assignments, leave_requests, request_segments, leave_ledger |
| Attendance | shift_templates, shift_assignments, clock_events, attendance_records, correction_requests, overtime_requests |
| Workflow | definitions, definition_versions, instances, steps, decisions, delegations |
| Lifecycle/tasks | lifecycle_templates, lifecycle_instances, tasks, task_evidence, hr_requests |
| Messaging | notifications, delivery_attempts, outbox_events |
| Integration | credentials, subscriptions, webhook_deliveries, import_batches, import_rows |
| Governance | audit_events, retention_policies, deletion_requests |

Integrity requirements:

- Every tenant-owned record has non-null tenant ownership. Global accounts do not expose tenant membership or HR facts automatically.
- Enforce same-tenant relationships at database level wherever feasible, including composite references. Tenant filters alone are insufficient.
- Store company context on company-owned records; employee identity alone must not unlock every employment or document.
- Use tenant/company-scoped uniqueness and indexes for common authorized list queries.
- Prevent overlapping effective-dated assignments where mutually exclusive; date intervals must use one documented boundary convention.
- Store UTC event timestamps, named timezones for schedules and date-only fields for business dates. Avoid floating-point leave amounts.
- UUID/opaque identifiers reduce guessability but never replace authorization.
- Prevent hard deletion of referenced historical records. Corrections use history or reversals; configured retention/purge remains a separate controlled process.

## 15. State machines

| Resource | Required transitions |
|---|---|
| Employment | draft → scheduled/active → ended; cancelled before activation; rehire creates another employment |
| Contract | draft → under review → approved → active → expired/superseded; rejected returns for revision |
| Document review | uploaded → quarantined → pending review → accepted/rejected; superseded tracked separately |
| Leave | draft → submitted → in approval → approved/rejected; submitted can withdraw; approved → cancellation requested → cancelled or cancellation rejected |
| Correction/overtime | draft → submitted → approved/rejected; submitted can withdraw |
| Workflow | pending → running → approved/rejected/cancelled; blocked when resolution needs intervention |
| Lifecycle | planned → running → completed/cancelled; blocked tasks remain explicit |
| Task | open → in progress → completed; cancelled; overdue is a derived flag |

Every transition must specify eligible actor, prerequisite, side effect, notification and audit event. State changes must use domain operations rather than unrestricted status edits.

## 16. API and integrations

**API-01:** Versioned REST endpoints with stable identifiers, pagination, scoped filters, structured validation errors and documented authorization. Representative families: `/api/v1/companies`, `/employees`, `/employments`, `/documents`, `/leave-requests`, `/attendance`, `/approval-tasks`, `/lifecycle-instances`, `/reports`.

**API-02:** First-party web sessions use secure cookies and CSRF protection. Integration credentials are separate, expirable, revocable and tenant/company/resource scoped. No general employee API token that bypasses UI restrictions.

**API-03:** Support explicit submit/approve/reject/cancel/correct operations. Validate idempotency keys for retryable integrations and version preconditions for conflicting updates. Tenant ownership is resolved from trusted authentication context, not accepted from an arbitrary request body.

**API-04:** Publish selected outbound events: employee created, employment started/ended/transferred, leave approved/cancelled, document status changed and attendance correction approved. Payloads carry event ID, schema version, occurrence time and permitted resource references with minimal personal information.

**API-05:** Sign webhooks, support secret rotation, delivery logs, retry backoff and controlled replay. Receivers must handle duplicates. Validate destinations and prevent access to internal/private service endpoints. Disable subscriptions when credentials/scope change.

**API-06:** Use transactional outbox for reliable committed event publication. Jobs carry explicit trusted tenant/company context and fail closed if missing.

SSO, Microsoft 365, Teams, ERP, payroll and biometric adapters remain subsequent integrations. Their future compatibility must not imply that they are already delivered.

## 17. Imports, exports and reports

**IMP-01:** CSV employee/employment and opening leave-balance templates with documented columns. Dry-run validation, row-level errors, duplicate detection and explicit confirmation precede writes. Preserve source batch and per-row outcomes. Replaying a confirmed batch must not duplicate employees or balances.

**EXP-01:** Scoped CSV exports for authorized workforce/report views. Record exports in audit history, protect generated files, expire downloads and mitigate spreadsheet formula injection. Large exports execute asynchronously.

**REP-01:** Headcount by company/department/location, hires/exits, tenure, upcoming probation, missing/expiring documents, leave usage/balances, attendance exceptions, overtime and overdue tasks.

**REP-02:** Report definitions must document date range, as-of date, timezone, population and exclusions. Count tenant-level unique employees separately from company employments. Transfers are not tenant exits; rehires must be distinguishable from first hires. Define turnover formula during review before displaying a percentage.

**REP-03:** Drill-down rows must reconcile to displayed totals. Historical headcount uses effective-dated employment records rather than current employee status. Financial payroll charts are outside V1.

## 18. Security, privacy and audit

**SEC-01:** Enforce isolation in HTTP requests, queue workers, CLI commands, caches, object storage, notifications, imports, search and aggregates. Cache keys and file ownership incorporate tenant context. Missing context fails closed.

**SEC-02:** Privileged users require MFA; configure secure session expiry, brute-force protection, password recovery and membership revocation. Secrets must not enter repository or logs.

**SEC-03:** Encrypt transport and stored backups/files using deployment-managed controls. Sensitive fields are separately authorized and masked in normal views. Define key rotation and recovery responsibilities before production.

**SEC-04:** Audit actor, subject, tenant/company, action, resource, timestamp, correlation ID, reason and redacted change summary. Record approvals, role changes, sensitive reads/downloads, exports and support access.

**SEC-05:** Normal application roles cannot update/delete audit events. This provides application-level append-only history; database administrators remain capable of changes unless separately controlled storage is introduced. Retention and authorized purge must therefore be documented rather than claiming absolute immutability.

**SEC-06:** Define retention by record category, access requests, corrections, legal holds and approved deletion/anonymization. Audit, backups and uploaded files must follow a coordinated policy. No legal retention period is assumed in this document.

**SEC-07:** Do not send medical documents, identity numbers or compensation in general notifications, error telemetry or webhooks. Restrict medical evidence separately from absence dates.

## 19. UAE and other jurisdiction requirements

The platform must support configurable leave types, working calendars, public holidays, probation/notice dates, document expiry and employment policies. Policies must be selectable by employing company and jurisdiction, with effective dates.

This document does not establish UAE statutory entitlement values, government integration requirements or legal compliance. Before production, the relevant HR/legal owner must approve the policies applicable to each company, including any free-zone or other employment regime. Verified authoritative sources and approval evidence must accompany preset statutory rules if introduced.

Payroll/WPS, gratuity, immigration submission and insurance enrollment are not V1 automated functions. Tracking a visa expiry is not a government integration.

## 20. Architecture and module boundaries

Suggested modules: Identity/Tenancy, Organization, Workforce, Documents, Leave, Attendance, Workflow, Lifecycle, Tasks/Notifications, Reporting, Integrations and Audit.

Shared infrastructure provides authorization, persistence conventions, private files, event publication and queue handling. Workforce owns employment truth; Leave owns balances; Attendance owns raw time events and calculations; Workflow orchestrates decisions but cannot bypass domain validations. Lifecycle consumes approved domain actions. Reporting reads authorized projections and never mutates business records.

Keep deployment as one application with separate worker/scheduler processes. Use service boundaries and events where they protect ownership, not an event for every internal function. Begin with relational data and bounded policy configuration; avoid premature microservices, an unrestricted rule language or a universal entity/attribute schema.

Before choosing dependencies, compare existing project capability, built-in framework support, maintained compatible packages and custom code. Record licenses and upgrade responsibility. Custom domain logic is justified where employment history, policy calculations and company scoping require precise behavior; generic authentication/upload/queue infrastructure should reuse established components where suitable.

## 21. Nonfunctional and operational requirements

Proposed pilot planning envelope: 10 tenants, up to 10 companies per tenant, 5,000 employees per tenant, 100 concurrent sessions and five years of retained attendance. These are validation assumptions, not demonstrated capacity or commercial promises.

| Area | Proposed release target |
|---|---|
| Interactive API | p95 below 800 ms for agreed representative list/detail/actions under pilot load, excluding uploads/exports |
| UI | Useful initial screen within 2.5 s on agreed test device/network |
| Scheduled work | Daily accrual/expiry runs complete before the configured local working day; failures alert operators |
| Service availability | Operational target 99.5% monthly; depends on selected hosting and excludes no events silently |
| Backup recovery | Proposed RPO ≤24 hours and RTO ≤8 hours; hosting/cost approval required |
| Upload limit | Proposed 20 MB per file; allowlist and tenant quota configured |

MUST: reproducible Docker deployment, environment separation, migrations with rollback/recovery plan, health/readiness checks, queue failure monitoring, scheduler heartbeat, structured redacted logs, secure configuration and dependency inventory. Database and private-file backups must be coordinated and restoration rehearsed. Do not expose database/Redis management ports publicly.

A minimum deployment runbook covers installation, secrets, initial tenant creation, releases, migration failure, worker restart, backup/restore, tenant suspension, credential rotation and incident handling. Temporary environments and test downloads must be cleaned while preserving project-required dependencies and final artifacts.

## 22. Verification and release acceptance

Required automated checks focus on business invariants and trust boundaries:

1. Two tenants and multiple companies; test read/write/download/export/report/API/job isolation and manipulated parent-child IDs.
2. Roles, self-approval, delegation, membership revocation and privileged-field access.
3. Leave calculations, effective dates, calendars, concurrent reservation, cancellation, carry-forward and idempotent accrual.
4. Overnight shifts, duplicate events, missing clocks, corrections and timezone boundaries.
5. Transfer/rehire history, manager cycles, blocked approvers and employment changes applied once.
6. Workflow snapshot versioning, stale decisions, retry failures and outbox publication.
7. Upload quarantine, private file access, restricted attachments, export permissions and redacted logs.
8. Import dry runs/replays, report reconciliation and restoration of database plus files.

Manual UAT must exercise full journeys in employee, manager, Company HR and Group HR roles on desktop/mobile. Include keyboard/screen-reader checks and practical task completion, not only screenshots.

Production release requires: agreed scope complete; isolation and critical invariants passing; no unresolved critical/high security defects; business owner sign-off on leave/attendance policies; demonstrated restore; monitored workers/scheduler; migration rehearsal; documented limitations; designated support owner; pilot UAT approval. Lower-severity issues need explicit owner and resolution plan.

## 23. Delivery phases and exit criteria

| Phase | Deliverables | Exit criterion |
|---|---|---|
| 0 — Review/discovery | Requirement decisions, existing code/product comparison, versions/dependency evaluation, threat model, RLS/worker spike | Approved architecture and bounded backlog |
| 1 — Foundation | Docker, auth/MFA, tenancy, company scopes, audit, files, queues, test fixtures | Isolation checks pass across web/jobs/files |
| 2 — Workforce | Organization, employee/employment/contracts, imports, history, transfers | Two-company transfer and rehire UAT pass |
| 3 — Approvals/tasks | Versioned bounded workflows, approvals, tasks, notifications/outbox | Self-approval, retries and blocked routes verified |
| 4 — Documents/leave | Review/expiry, policies, ledger, requests/calendars | Document access and balance invariants pass |
| 5 — Attendance/lifecycle | Shifts/events/corrections, onboarding/probation/offboarding | Overnight attendance and lifecycle UAT pass |
| 6 — Reporting/integrations | Reconciled dashboards, exports, scoped APIs/webhooks | Report/access/delivery tests pass |
| 7 — Production pilot | Load/accessibility/security checks, restore, migration rehearsal, training/runbooks | Release acceptance and owner approval |

Approval infrastructure precedes leave and lifecycle because they depend on it. Do not estimate dates until team capacity, reuse opportunities and acceptance decisions are known. Each phase should produce a usable tested increment; no phase is complete merely because screens exist.

## 24. Decisions required before implementation

| Decision | Recommended starting position | Why it matters |
|---|---|---|
| Existing system or new repository | Inspect Company Tools and alternatives first; reuse only if ownership/security fit | Avoid duplicated auth and infrastructure |
| Company/legal entity meaning | Company equals employing legal entity | Prevent unnecessary hierarchy |
| Tenant isolation strategy | Shared PostgreSQL; evaluate RLS with application scopes | Changes persistence and operational design |
| Concurrent employments | Preserve schema possibility; disable overlapping active employment in V1 UI | Leave/attendance ownership becomes more complex |
| Leave policies | HR supplies approved rules and opening balances per company | Necessary for correct entitlement |
| Leave reservation | Reserve on submission | Prevent concurrent overspending |
| Transfer balance handling | Explicit company-approved settlement/migration | Entitlements cannot be assumed portable |
| Approval structure | Sequential steps and controlled delegation | Bounds workflow complexity |
| Attendance policies | Web clocking, overnight shifts, no location tracking by default | Privacy and operational behavior |
| Authentication | Session login and privileged MFA; SSO later | Identity and account lifecycle |
| Hosting/backup | Confirm environment, budget and recovery targets | Operational promises need funded capacity |
| Data retention | Category-specific HR/legal-approved policy | Governs archival, purge and backups |
| Language | English V1; prepare localization structure | Arabic/RTL adds delivery scope |
| Initial migration | Employee/employment plus opening leave balances | Historical migration adds reconciliation work |
| Product name/branding | Working name “HR Platform” | Can be finalized without blocking domain design |

These proposed defaults permit a concrete review without treating uncertainty as approval. Implementation starts only after the requirements and material decisions are approved.

## 25. Longer-term roadmap

Phase 2 may add recruitment/onboarding integration, performance/goals, structured HR service desk, assets and expenses. Payroll should be a separately approved project with jurisdiction rules, finance reconciliation and validation against trusted calculations. AI comes after permissions and reliable records: start with scoped retrieval, summaries and drafted requests; consequential employment and compensation decisions remain human-authorized. SaaS billing, tenant quotas and enterprise isolation are separate commercial/operational scopes.

## 26. Definition of ready for development

A module is ready when its owner, stories, data relationships, permissions, state transitions, policy inputs, failure cases and acceptance tests are defined. A module is done when those outcomes are verified, its operational behavior is documented, and remaining limitations are visible.

Next deliverables after requirements approval: final logical ERD, detailed permission matrix, endpoint schemas, workflow transition contracts, module backlog and first-phase implementation plan. Those artifacts refine this baseline; they must not introduce unapproved modules or silently expand scope.
