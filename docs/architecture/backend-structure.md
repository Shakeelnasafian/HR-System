# Backend structure

Status: adopted during the Laravel structure refactor (October 2026). This describes where backend code lives and the
rules each layer follows. Business rules themselves are documented in the module contracts (access, workforce, outbox).

## Layout

| Path | Holds |
|---|---|
| `app/Http/Controllers/Api/{Module}` | Thin controllers: take a Form Request, call one Action or query, return a Resource |
| `app/Http/Requests/{Module}` | Validation and route-company authorization (`CompanyRequest`) |
| `app/Http/Resources/{Module}` | Explicit JSON contracts, one per representation |
| `app/Models/{Module}` | Eloquent entities, relationships, casts and query scopes (`User` stays in `app/Models`) |
| `app/Policies` | `CompanyPolicy`: one Gate ability per company permission |
| `app/Actions/{Module}` | Business operations and workflow rules, one public `handle()` each; `Actions/Fortify` for Fortify contracts |
| `app/Services/{Tenancy,Audit,Messaging}` | Shared capabilities: tenant context and company access, audit and security events, the outbox |
| `app/Console/Commands` | Artisan commands (`routes/console.php` keeps only the schedule) |
| `app/Jobs` | Queue entry points; scalar ids only, context re-established in the job |
| `app/Providers` | Bindings, policies, rate limiters, event listeners |
| `routes/api.php` | Route declarations only, grouped by module |

Modules are Account (signed-in user), Tenancy (context, capabilities, bundles, company access, invitations),
Organization (company settings, organization units, calendars, profile field settings), Workforce (employees,
employments, assignments, reporting lines, private profiles) and Audit. URL versioning (`/api/v1`) is independent of
folders; there is no `V1` namespace.

## Request lifecycle

1. `auth:sanctum`, then `tenant` (`TenantRequest`): one database transaction per request, `app.tenant_id` set for RLS,
   membership and MFA checked. It runs before `SubstituteBindings`, so any route model binding sees the tenant context
   (`TenantRoutingTest`). Responses of 400 or more roll the transaction back.
2. The Form Request authorizes the route company through `CompanyPolicy` and then validates. Every denial is a 404;
   an MFA-unverified holder of a privileged permission gets the 403 from `CompanyAccess` before any company is resolved.
   Child resources that do not belong to the authorized company are 404s too, checked before validation where the
   API already behaves that way.
3. The controller calls an Action. Actions that mutate re-read what they depend on under lock
   (`$request->company(lock: true)`, `lockForUpdate()` on the aggregate), then check versions (409) and invariants
   (422/409) inside the request transaction, then write, audit (`Audit::record`) and record outbox events
   (`Outbox::record`) in that same transaction. Actions do not open their own transactions.
4. A Resource renders the response.

## Rules

- **Tenant isolation.** Tenant models use `BelongsToTenant`: every query is filtered to the context tenant and throws
  outside a context. RLS stays the database boundary. Bypass the scope only for deliberate cross-tenant reads (the
  `/me/tenants` selector) and say why at the call site.
- **Company scoping.** Never query `companies` directly for a request; use `CompanyAccess::query()/find()` or a
  `CompanyRequest`. Company-owned children are always constrained by `company_id` of the authorized company.
- **No implicit binding for tenant data.** Route parameters stay strings; resolve children after authorization.
- **Lock order** is part of the contract: company row, then the reporting-line advisory lock, then the employee row
  (per-person aggregate), then the employment row, then `FOR SHARE` reads of referenced rows. Do not add, drop or
  reorder locks during structural changes.
- **Query Builder or SQL** remains where it is clearer or required: recursive reporting-line checks, `DISTINCT ON`
  report queries, outbox claiming (`SKIP LOCKED`), SECURITY DEFINER functions, advisory locks, PostgreSQL arrays and
  jsonb operators, and bootstrap reads before a tenant context exists. Leave a one-line reason.
- **Resources** list fields explicitly. Timestamps are emitted as stored (`getRawOriginal`), dates as `Y-m-d` strings,
  jsonb lists as arrays, `audit_events.changes` as the stored jsonb text, empty field maps as `{}`. Paginated listings
  use `Resource::collection($paginator)` (the `data`/`links`/`meta` envelope).
- **Contract.** `tests/Feature/ApiContractTest.php` compares every endpoint with `tests/Contracts/api-v1.json`. Regenerate
  the snapshot (`CONTRACT_UPDATE=1`) only for an approved API change, in its own commit.
- **Jobs** carry scalar ids (`tenantId`, `actorId`, event ids) and rebuild context with `TenantContext::run/runSystem`
  (`UseTenantContext`), so model restoration never queries tenant data without a context.
- **No extra layers.** No repositories, no interfaces for single implementations, no per-module service classes that
  regrow controller-sized logic. Shared read queries go in model scopes or a small single-purpose class next to the
  Actions that use it. Such guards live beside the Actions that share them and keep descriptive method names:
  `Tenancy\ManagerAuthority` (actor and held permissions under the company lock), `Tenancy\LockPendingInvitation`,
  `Organization\LockCalendar`, `Workforce\ReportingLine` (advisory lock and person-level cycle check),
  `Workforce\AssignmentReferences` and `Workforce\CurrentAssignments`.

## Where things moved (October 2026 restructure)

| Before | After |
|---|---|
| `app/Tenancy/*Controller`, `InvitationAcceptance`, `CompanyAdministration` | `Api/Tenancy/*` controllers, `Requests/Tenancy`, `Actions/Tenancy`, `Resources/Tenancy` |
| `app/Organization/*Controller`, organization-unit and profile-field methods of the old workforce/profile controllers | `Api/Organization/*` |
| `app/Workforce/WorkforceController`, `AssignmentController`, `ProfileController`, `Assignments` | `Api/Workforce/*`, `Api/Audit/AuditEventController`, `Actions/Workforce` |
| `app/Tenancy/{TenantContext,CompanyAccess,PermissionCatalog}`, `ScopesCompany` trait | `Services/Tenancy`; the trait was replaced by `CompanyRequest`/`CompanyPolicy` |
| `app/Audit/*`, `app/Messaging/*` | `Services/Audit`, `Services/Messaging` (`AuditServiceProvider` in `app/Providers`) |
| `ProjectedRow` (generic row serialization) | Explicit Resources per representation |
| Closures in `routes/api.php` and `routes/console.php` | `Api/Account`, `Api/Tenancy/ContextController`; `app/Console/Commands` |
