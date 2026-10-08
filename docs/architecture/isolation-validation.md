# Tenant isolation threat model and validation spike

Status: design only; no SQL, worker or application tests have been run. This spike must pass before domain implementation relies on RLS. It is the first proposed executable work after separate implementation approval.

## Threats and controls

| Threat | Proposed control | Evidence required |
|---|---|---|
| Manipulated tenant/company/parent IDs | Trusted context, scoped lookups, policies and composite foreign keys | Denied reads/writes; no metadata/count leaks |
| Missing ORM scope or raw query | Tenant RLS using non-owner runtime role | Missing context sees no rows and cannot write |
| Company scope confusion | Permission-attached scopes and safe field projections | A-HR/B-employee cannot acquire B-HR powers |
| Reused DB connection or worker leaks context | Transaction-local context, fail-closed jobs, cleanup after failures | Alternating tenants remain isolated across success, rollback and retries |
| User revoked after enqueue | Reload principal authority on execution and delivery | Revoked export/notification cannot reveal data |
| Direct file access | Private objects, authorized download and class/audience checks | Other tenant/company and quarantined objects inaccessible |
| Stale SPA response/cache | Tenant-aware keys and context-generation checks | Old response never appears after tenant switch |
| Role escalation / self approval | Bounded grant authority and actor/subject checks | Grant escalation, delegation loop and own approval denied |
| Outbox retry/crash | Unique business keys, transactional effects, deduplicated delivery | No duplicate business mutation; delivery attempts remain traceable |
| Malicious webhook/upload | Destination validation, restricted egress, quarantine | Private-network destinations blocked; scan failures stay private |
| Compromised application DB credential | Least privilege and separate migration/backup roles | Runtime cannot alter policies or access administrative functions |

RLS is defense against missing tenant predicates, not a guarantee against a fully compromised application that can set arbitrary trusted context. It does not protect private files, Redis, logs or frontend caches. Company/field access remains an application trust boundary in this proposal.

## RLS design to test

Official PostgreSQL documentation states that table owners normally bypass RLS, superusers and BYPASSRLS roles bypass it, and FORCE ROW LEVEL SECURITY can subject owners to policies. Policy visibility and write checks must both be considered. Source: [PostgreSQL 18 row security](https://www.postgresql.org/docs/18/ddl-rowsecurity.html).

Proposed runtime role is neither table owner, superuser nor BYPASSRLS, cannot change roles to an elevated principal, and has no DDL/TRUNCATE privileges. Migrations run separately. Tenant tables enable and force RLS. The tenant predicate uses a transaction-local `app.tenant_id` set from verified authority; unset/empty context denies access. Define visibility and write checks so updates cannot move a row between tenants. Test malformed context as a denied operation, with no confidential error output.

Before any tenant model is loaded, open a short transaction and set context locally. Perform reads, domain writes, audit and outbox work in that boundary. End it before slow external work. No session-level tenant setting that can persist into another job. Async jobs serialize scalar IDs and context, not Eloquent models that may restore before tenant middleware. Reload resource and principal only after context setup.

Global identity discovery is the bootstrap exception: a narrow path reads only the authenticated user's memberships and tenant service status, not general employee tables. Its database grants and queries must be reviewed separately to avoid a circular RLS dependency. Global users, own-membership discovery, migration access, retention and backups cannot be solved by a blanket tenant scope.

## Spike fixtures

Two tenants T1/T2, each with companies A/B, at least one employee per company, and one transferred employee. Include a user with T1-A HR plus T1-B self access, another user in both tenants, a manager, an employee, a suspended tenant and a revoked membership. Use synthetic identifiers, documents, assignments and exports. Seed through a setup role; execute security assertions through the actual runtime role.

## Required experiments

| ID | Experiment | Pass condition |
|---|---|---|
| ISO-01 | Direct SQL select/insert/update/delete without context | No readable tenant rows or successful tenant writes |
| ISO-02 | Raw SQL, ORM joins, aggregates, eager loads and nested routes under T1 | No T2 row, count, name or associated data |
| ISO-03 | Cross-tenant/company parent reference; changing tenant ownership | Foreign key/check/policy denies invalid relationship |
| ISO-04 | A-HR/B-self grants and group HR limited company set | Correct per-role scope; no union amplification |
| ISO-05 | One connection: T1 success, T2 success, T1 rollback, no-context operation | No stale context survives transaction boundaries |
| ISO-06 | Same long-lived worker: T1/T2 alternation, exception, retry, missing context | No cross-tenant reads/writes; missing context fails and alerts |
| ISO-07 | Queue job reconstruction and resource reload | No tenant model query before trusted context established |
| ISO-08 | Revoke membership/suspend tenant after enqueue | Business work stops; scoped system recovery remains possible |
| ISO-09 | Two tabs with different tenant selectors; switch during pending fetch | Correct resource on each request; old responses discarded |
| ISO-10 | Export request, generation, download after scope removal | Access rechecked at each stage; private file remains protected |
| ISO-11 | Document names/content, quarantine, historical company audiences | No metadata/file leakage; scan error never releases bytes |
| ISO-12 | Connection pool reset/reconnect; nested transactions and deadlock retry | Context restored from verified authority per retry, never inherited |
| ISO-13 | Runtime attempts DDL, role escalation, disabling RLS, audit update/delete | Permission denied; table-owner test cannot create false confidence |
| ISO-14 | DB/file backup and controlled restore | Full intended tenant population restored and isolation still passes |
| ISO-15 | Representative authorized list query plans with RLS | Explain plans and timings recorded; no unjustified table-wide scans |

Use PostgreSQL, not SQLite, for these tests. Exercise actual queue process reuse; a synchronous fake queue cannot establish worker isolation. Verify request middleware order before route binding and model restoration. Add schema checks that every tenant table has ownership constraints and policies, so future migrations cannot silently omit protection.

## Exit decision and artifacts

Record runtime versions, role grants, policy definitions, failing/passing cases, query plans, rollback/retry behavior and test commands. If context bootstrap, workers or restore cannot pass, stop and revise A02 before broad implementation. Do not quietly disable RLS and claim the design passed. Evaluate application-only scoping or per-tenant databases as explicit alternatives with changed risk/operations costs.

Successful spike results authorize no production release. Load, backup, accessibility, HR policy UAT and full module tests remain later gates. This document is the experiment specification, not its results.
