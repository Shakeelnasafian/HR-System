# Standalone SPA and API contract proposal

The owner requires an API-based SPA without Inertia. React owns browser navigation; Laravel exposes JSON and domain operations. No Blade/Inertia page props, shared server routing dependency or frontend access to database models.

## Authentication and deployment

Recommended first-party flow uses Sanctum's cookie sessions and Fortify's headless authentication. Laravel documents this flow and its same-top-level-domain constraint: [Sanctum](https://laravel.com/docs/13.x/sanctum), [Fortify](https://laravel.com/framework/docs/13.x/fortify).

Serve both builds through one origin initially. Initialize CSRF with `GET /sanctum/csrf-cookie`, then submit JSON to Fortify login/MFA endpoints. Send cookies, `Accept: application/json` and the decoded XSRF token header on mutations. Session cookies are HttpOnly, Secure in production, with an explicitly reviewed SameSite policy. Do not store session credentials in localStorage. Disable public self-registration initially; tenant invitation/provisioning is controlled.

Privileged users without completed MFA can access enrollment/recovery routes only, not tenant administration or HR operations. Password reset and MFA recovery must invalidate appropriate sessions and audit security changes. Prevent open redirects in frontend return URLs. Use rate limits for authentication, invitation and recovery endpoints.

For sibling subdomains, allow only configured origins with credentials, configure stateful domains and cookie scope, and test preflight behavior. Never combine credentialed CORS with a wildcard origin. A separate frontend build does not require separate domains.

## Request-bound tenant selection

Use proposed header `X-Tenant-ID` for tenant-owned API endpoints. It is an untrusted selector: the server authenticates, loads membership and verifies access before constructing trusted tenant context. Do not accept `tenant_id` from mutation bodies or treat the header as proof of access. Integration credentials are bound to their tenant; mismatched selectors fail.

`GET /api/v1/me` and `GET /api/v1/me/tenants` bootstrap global identity and the user's own membership list without tenant context. Tenant-owned endpoints require the selector and fail closed if absent. Do not silently default to the first tenant. `company_id` is an authorized filter/reference, never an authority grant. An all-company view intersects assigned scopes.

This avoids a session-wide tenant toggle changing another browser tab's target unexpectedly. The SPA keys queries by identity, tenant, company and filters; clears sensitive state on logout/revocation; cancels or ignores old-context responses during switching. Cross-tab logout should clear cached HR content. Sensitive API responses use no-store caching; do not persist HR query caches or add an offline service worker in V1.

## Contract conventions

| Concern | Proposed contract |
|---|---|
| Version | `/api/v1`; auth/CSRF framework routes remain separately documented |
| Detail | `{ "data": { ...permitted_fields } }` |
| Lists | `{ "data": [], "links": {...}, "meta": {"current_page":1,"per_page":25,"total":0} }`; bounded maximum 100 rows; stable ordering |
| Errors | `{ "message":"...", "code":"...", "errors": {"field":["..."]}, "request_id":"..." }`; no SQL, stack traces or hidden record facts |
| Statuses | 401 unauthenticated; 403 known forbidden action; 404 hidden/out-of-scope resource; 409 invalid transition/idempotency conflict; 412 stale version; 422 input errors; 429 rate limit; 419 CSRF/session handling |
| Tenant context | Missing selector 400 `tenant_context_required`; invalid/unavailable membership 403 with generic message; no tenant existence disclosure |
| Concurrency | Return resource ETag from integer version; require `If-Match` on versioned updates/transitions; missing required precondition 428 |
| Retry safety | `Idempotency-Key` for replayable create/domain commands; scope by tenant + principal + operation; store request hash, resulting resource and response status atomically |
| Idempotency conflict | Same key with different request hash returns 409; same successful operation replays safely after current authorization check |
| Dates | ISO business dates; UTC timestamp strings with explicit offset; named timezone for schedule interpretation |
| Amounts | Decimal strings plus explicit unit; no float leave amounts |
| Sensitive values | Explicitly allowlisted projections; omit denied fields rather than returning placeholders that leak existence |
| Validation | Reject unknown write fields; whitelist filters, includes and sort keys; no generic arbitrary model filtering |

Idempotency response retention and request size/rate limits must be set before endpoint release. Durable domain operation uniqueness must survive expiration of the response cache. Never automatically replay a consequential mutation after a 401/419 unless the same idempotency key and its retry semantics are valid. For uncertain outcomes, check operation status and preserve user input.

## Foundation endpoints to specify and test first

These are proposed contracts, not implemented routes.

| Endpoint | Input / output | Authority |
|---|---|---|
| `GET /api/v1/me` | Current user ID, display name, MFA state; no cross-tenant employee details | Authenticated identity |
| `GET /api/v1/me/tenants` | Active memberships and safe tenant display names | Current identity only |
| `GET /api/v1/context` | Active tenant, authorized company summaries and effective UI capability hints | Valid tenant selector/membership |
| `GET /api/v1/companies` | Paginated authorized companies | Company read scope |
| `POST /api/v1/companies` | Name, code, timezone; result ID/version; tenant derived server-side | Tenant company-create grant |
| `PATCH /api/v1/companies/{id}` | Allowlisted fields plus `If-Match` | Company-update grant |
| `POST /api/v1/invitations` | Email, proposed roles/scopes, expiry; safe invitation status | Invite and grant authority |
| `POST /api/v1/memberships/{id}/revoke` | Reason, version; invalidates access | Membership-revoke authority |
| `GET /api/v1/audit-events` | Bounded permitted events; redacted fields | Explicit audit read |

Company archival, invitation acceptance and role-scope management need full request/response schemas before implementation. Auth endpoints reuse Fortify where suitable; document their JSON shapes instead of inventing a parallel password/MFA system.

Later domain families remain those in the requirements. Explicit actions include leave submit/approve/reject/cancel-request, attendance correction approve, employment transfer and document review. They must call the same domain authorization/actions regardless of whether invoked from SPA or integration routes. Third-party API tokens are separate scoped service identities, not first-party browser credentials.

## SPA responsibilities and acceptance

Provide login/MFA, tenant/company selector, authorized navigation, loading/empty/error states, accessible validation and a responsive shell. Route guards improve UX but do not enforce security. Company switching must reset incompatible filters. Stale update responses prompt reload/review rather than silently overwriting another user's work.

Before the first module, publish machine-readable OpenAPI schemas for its endpoints and validate actual responses against them. Generate TypeScript DTOs if a reviewed tool reduces duplication; do not claim types already exist. Test login, MFA challenge, CSRF failure, refresh/deep links, expired sessions, hidden resource IDs, simultaneous tabs in different tenants, tenant switching with requests in flight and zero unauthorized fields in network responses.
