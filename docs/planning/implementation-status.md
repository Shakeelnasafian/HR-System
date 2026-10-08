# Foundation implementation status — 8 October 2026

The owner authorized continuing the project with the API/SPA foundation. An independent build is underway in the supplied repository; Company Tools source has not been inspected and no reuse claim is made. The API-based React SPA requirement is preserved. No production release or merge has occurred.

## Implemented

- Separate Laravel JSON API and React/TypeScript SPA, with locked package versions.
- Headless Fortify login/password reset/MFA and Sanctum cookie authentication; no public registration.
- Tenant membership discovery, explicit tenant selector, company read grants and safe company projections.
- PostgreSQL tenant RLS for companies/grants and same-tenant composite foreign keys.
- Transaction-local tenant context shared by requests/jobs, fail-closed missing context and execution-time membership checks.
- MFA enrollment and fresh MFA login for workspaces marked as requiring it.
- Local Docker services, separate owner/runtime database roles, explicit synthetic demo provisioning and CI.

## Boundary and known gaps

This is the first usable foundation slice, not completion of F00–F04 or all V1 security requirements. Company grants currently represent `company.read`; the complete role/action catalog, assignments, invitations, tenant suspension UI and grant administration are not implemented. Membership/service bootstrap tables intentionally sit outside tenant RLS and are accessed through narrow authenticated queries; this exception needs continued review as administration grows.

The current code has no employee, document, export, leave or workflow endpoints. Consequently ISO-10/11 document/export tests, coordinated file restore (ISO-14), representative load/query plans (ISO-15) and the full F04 spike are outstanding. Nested/deadlock/reconnect/pool cases in ISO-12 and broad schema-coverage enforcement also need expansion. No connection pool is configured.

There is no production audit trail, transactional outbox or monitoring stack yet. These remain F07–F09, before workforce implementation or production use. Domain-level action authorization must accompany every future mutation; tenant RLS does not replace company or field permissions.

## Validation evidence

- Local frontend production build and three frontend tests passed, including stale tenant-response rejection and MFA gating.
- CI run [37782639749](https://github.com/Shakeelnasafian/HR-System/actions/runs/37782639749): API job passed 13 tests against PHP 8.5/PostgreSQL 18/Redis 8. It covers missing-context SQL, cross-tenant rows/writes, composite relations, company grants, tenant revocation/suspension, MFA and real queue-worker reuse after failure.
- Docker/browser evidence is being finalized; see the PR checks for the latest exact commit. A passing suite does not establish all planned isolation experiments or production readiness.

Backend runtime tests were executed in GitHub Actions because this workspace has no PHP/PostgreSQL/Docker. A system package attempt failed before installation; no local PHP or database runtime was installed. Project dependencies are retained; task-only downloads and caches are cleaned after verification.

## Versions and provenance

Initial locked versions: Laravel 13.35.0, Fortify 1.41.0, Sanctum 4.3.3, Predis 3.6.1. The lockfile was resolved in CI and imported as a reviewed artifact, not handwritten. See `backend/composer.lock` and `frontend/package-lock.json` for the full resolved inventory.

The Laravel skeleton was taken from `laravel/laravel` commit `f4000aeb018fcbf71d4a13e3ee4b80c7e2d45be5`. Fortify source at `f7c3fd787a64ada544353c0423e4589b1626ec75` was read to verify integration; runtime uses the stable lockfile version. Vite scaffolding was created with create-vite 9.2.1. Framework authentication and queue functionality are reused; no custom password/MFA algorithm is included.
