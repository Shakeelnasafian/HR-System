# Organization and workforce increment contract

This increment implements company-scoped organization and the initial employment lifecycle. It does not complete Phase 2 or the V1 release.

All routes below require a session, CSRF on writes, active membership, required MFA and `X-Tenant-ID`. Company ownership is taken from the route. Unknown or unauthorized company/resource IDs return 404. Bodies cannot supply tenant ownership. Lists use `data/links/meta`, max 100 per page. Mutations are transactional and audited; mutable resources require integer `version`, returning 409 on conflict. Unknown fields are ignored, never mass assigned.

| Route after `/api/v1/companies/{company}` | Behavior | Exact company grant |
|---|---|---|
| GET `/capabilities` | Names of current actor's company permissions | company.read |
| GET `/organization/{kind}` | List departments, locations or positions, including archived | organization.read |
| POST `/organization/{kind}` | Create {code,name} | organization.write |
| PATCH `/organization/{kind}/{id}` | {name,version,archived}; archival preserves references | organization.write |
| GET `/employees` | Safe directory search `q`, paginated; only company-linked people | workforce.read |
| POST `/employees` | New employee {employee_number,legal_name,preferred_name?} and draft employment {employment_number,start_date,department_id?,location_id?,position_id?} | workforce.write |
| GET `/employees/{id}` | Safe identity and this company's employment history only | workforce.read |
| POST `/employees/{id}/employments` | Rehire existing company-linked person; new draft {employment_number,start_date,organization IDs?} | workforce.write |
| POST `/employments/{id}/activate` | {version,reason}; draft only, start must be today/past, reject overlapping employment in any company | workforce.write |
| POST `/employments/{id}/cancel` | {version,reason}; draft only | workforce.write |
| POST `/employments/{id}/end` | {version,reason,end_date}; active only; exclusive end after start and no later than tomorrow | workforce.write |
| GET `/audit` | Redacted event metadata for company, paginated | audit.read |

Organization kind is exactly departments, locations or positions. References must be active and match both tenant and company. Employee number is tenant-unique; employment number company-unique. Conflict responses do not disclose the existing employee. Search exposes no contact, medical, identity-number, or compensation fields. This increment collects only employee number and names, avoiding unnecessary personal data.

Employment transitions lock the tenant employee aggregate before checking intervals. Historical intervals are `[start_date,end_date)` and timestamps UTC. Company timezone determines today. Future activation must be explicitly performed on/after its start date; no unimplemented scheduler is implied. Rehire preserves employee identity and ended history. End/cancel requires a reason; audit stores changed field names and transition metadata, never personal values or arbitrary request contents. Application runtime has SELECT/INSERT only on audit events and FORCE RLS applies.

Concurrent duplicate creates are rejected by scoped database unique constraints. Create requests are not automatically retried. Versioned transitions cannot apply twice. General idempotency-key support and transactional outbox are pending.

Deferred: transfers with reconciliation, scheduled activation, effective-dated assignments/manager cycles, employment terms/contracts, full private profile and self-service, CSV import, role/invitation administration, calendars, document storage and downstream HR modules. No transfer endpoint is exposed until reconciliation dependencies exist. Existing company grants are administered through the scoped [access API](access-contract.md); attaching new members to a company still requires operator provisioning until invitations exist.

## Company settings and working calendars (I4)

`GET /companies/{company}` returns `version`. `PATCH /companies/{company}` requires the privileged `company.manage` permission (MFA-verified session) and accepts `{version, reason, name?, timezone?}`; the company row is locked and the version checked inside the lock. The timezone must be one of the server's IANA identifiers, served to the SPA by `GET /api/v1/timezones` (authenticated, no tenant header) so browser alias lists cannot drift. Company codes are immutable, unchanged requests return 200 without an audit event, and new companies must specify a timezone (the old `Asia/Dubai` column default was removed). The company timezone determines "today" for employment activation and each calendar's `current_pattern`; company creation awaits tenant-level permissions.

Calendars belong to one company: `organization.read` views and `organization.write` changes them through `/companies/{company}/calendars[/{calendar}[/patterns|/holidays[/{holiday}]]]`. Weekly patterns are append-only ISO-weekday history (1=Mon..7=Sun, non-empty, distinct, one per `effective_from`); the runtime role has only SELECT/INSERT on patterns. Holidays are unique per date. No weekend or holiday data is preset. Every calendar mutation locks the calendar row, checks its `version` (409 if stale), requires a reason, increments the version and writes an audit event with codes and dates only. Archived calendars reject pattern and holiday changes with 409. All calendar tables use FORCE RLS with composite company and calendar keys; IDs from another company, calendar or tenant return 404.

Leave prerequisite: patterns can be backdated and holidays changed for past dates, so leave requests (LEV-04) must snapshot the resolved working days used in each calculation rather than re-reading calendars later.
