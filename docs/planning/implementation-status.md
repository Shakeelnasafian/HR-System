# Implementation status — 8 October 2026

The owner authorized continuing the project with the API/SPA foundation. An independent build is underway in the supplied repository; Company Tools source has not been inspected and no reuse claim is made. The API-based React SPA requirement is preserved. The owner merged documentation PR #1 into main and foundation PR #2 into the documentation branch. The owner merged PR #3 into main with the foundation and workforce additions. The company permission administration increment follows on its own branch. No production deployment has occurred.

## Implemented

- Separate Laravel JSON API and React/TypeScript SPA, with locked package versions.
- Headless Fortify login/password reset/MFA and Sanctum cookie authentication; no public registration.
- Tenant membership discovery, explicit tenant selector, company read grants and safe company projections.
- PostgreSQL tenant RLS for companies/grants and same-tenant composite foreign keys.
- Transaction-local tenant context shared by requests/jobs, fail-closed missing context and execution-time membership checks.
- MFA enrollment and fresh MFA login for workspaces marked as requiring it.
- Local Docker services, separate owner/runtime database roles, explicit synthetic demo provisioning and CI.

## Organization and workforce increment

The owner requested continuing the remaining modules. The next usable increment adds:

- Company-scoped departments, locations and positions with create, rename, archive and version checks.
- Searchable/paginated safe employee directory; minimum identity fields and a draft employment created atomically.
- Employment activation, cancellation, ending and rehire, with history preserved and explicit reason/version preconditions.
- Employee-parent locking across companies to prevent overlapping active intervals, with a two-process PostgreSQL concurrency test.
- Explicit company action grants, same-company organization foreign keys, immediate permission revocation checks, and RLS on every new tenant table.
- Append-only application audit records and a company-scoped audit viewer.
- React forms, employee timeline, organization editor and company navigation, all calling the JSON API.

See [workforce contract](../architecture/workforce-contract.md) for exact routes, payloads and lifecycle boundaries and [module tracker](module-tracker.md) for the remaining delivery sequence. This is an initial workforce increment, not completed Phase 2.

## Boundary and known gaps

This is the first usable foundation slice, not completion of F00–F04 or all V1 security requirements. Company grants now distinguish company read, organization read/write, workforce read/write and audit read. The full V1 role/action catalog, reusable role assignments, invitations and tenant suspension UI remain incomplete. Existing direct company grants now have scoped administration. Membership/service bootstrap tables intentionally sit outside tenant RLS and are accessed through narrow authenticated queries; this exception needs continued review as administration grows.

The code now includes organization, safe employee directory, initial employment lifecycle and company audit endpoints. Document, export, leave and workflow endpoints remain unimplemented. Consequently ISO-10/11 document/export tests, coordinated file restore (ISO-14), representative load/query plans (ISO-15) and the full F04 spike are outstanding. Nested/deadlock/reconnect/pool cases in ISO-12 and broad schema-coverage enforcement also need expansion. No connection pool is configured.

Organization/workforce mutations now write transactionally to an RLS-protected audit table; runtime has only SELECT/INSERT privileges on that table. Coverage of authentication, sensitive reads and administrative events is still incomplete. Private-file quarantine, transactional outbox and operational monitoring remain F07–F09 prerequisites for downstream modules and production use. Domain-level action authorization must accompany every future mutation; tenant RLS does not replace company or field permissions.

## Validation evidence

- Local frontend production build and three frontend tests passed, including stale tenant-response rejection and MFA gating.
- CI run [37782639749](https://github.com/Shakeelnasafian/HR-System/actions/runs/37782639749): API job passed 13 tests against PHP 8.5/PostgreSQL 18/Redis 8. It covers missing-context SQL, cross-tenant rows/writes, composite relations, company grants, tenant revocation/suspension, MFA and real queue-worker reuse after failure.
- CI run [37824764950](https://github.com/Shakeelnasafian/HR-System/actions/runs/37824764950), commit `c5917e7b79a2e8945c733b3bc63522c8682b7070`: 22 API tests / 136 assertions passed, including the two-process activation race, employment lifecycle/history, scope/revocation, same-company references and append-only audit. SPA build/tests passed; local lint passed. The complete Docker browser journey subsequently passed in [run 37826482145](https://github.com/Shakeelnasafian/HR-System/actions/runs/37826482145) at commit `698462e6f882afbdc163e14179ca895183bfeac3`, including MFA, company scope, organization/employee creation, activation/end, audit, mobile overview and logout. Four frontend tests passed, including the asynchronous form regression.
- A passing suite does not establish all planned isolation experiments or production readiness.

Backend runtime tests were executed in GitHub Actions because this workspace has no PHP/PostgreSQL/Docker. A system package attempt failed before installation; no local PHP or database runtime was installed. Project dependencies are retained; task-only downloads and caches are cleaned after verification.

## Versions and provenance

Initial locked versions: Laravel 13.35.0, Fortify 1.41.0, Sanctum 4.3.3, Predis 3.6.1. The lockfile was resolved in CI and imported as a reviewed artifact, not handwritten. See `backend/composer.lock` and `frontend/package-lock.json` for the full resolved inventory.

The Laravel skeleton was taken from `laravel/laravel` commit `f4000aeb018fcbf71d4a13e3ee4b80c7e2d45be5`. Fortify source at `f7c3fd787a64ada544353c0423e4589b1626ec75` was read to verify integration; runtime uses the stable lockfile version. Vite scaffolding was created with create-vite 9.2.1. Framework authentication and queue functionality are reused; no custom password/MFA algorithm is included.

## Company permission administration — 9 October 2026

Adds a code-defined permission catalog and company-scoped API/SPA to review and replace an existing member's exact grants. Uses the shared audit module, current database grants, mandatory administrator MFA and a company-wide version/lock. Self-changes and additions/removals beyond the administrator's own company authority are denied. Privileged targets must require MFA. The SPA preserves uneditable grants and shows added/removed permissions before confirmation.

This is administration of existing direct company grants, not invitation provisioning or reusable role bundles. See [access contract](../architecture/access-contract.md). Five frontend tests, production build and lint pass locally. CI run [37887158290](https://github.com/Shakeelnasafian/HR-System/actions/runs/37887158290), commit `3e79716edb2e823fec87911fe0ee3affb06960b3`, passed 29 backend tests / 181 assertions, including two independent processes competing to edit grants. The Docker browser permission flow passed in run [37886899770](https://github.com/Shakeelnasafian/HR-System/actions/runs/37886899770); its desktop screenshot was inspected. Later changes format the SPA, isolate test identities and remove the obsolete unreferenced Workforce audit class; the shared Audit class is the implementation in use. Latest exact-commit checks remain visible on PR #4.


## Company permission templates — 9 October 2026

Draft PR #5 adds immutable, company-scoped permission bundles with creation, additive copy into the existing member permission preview, and idempotent archive. Mandatory administrator MFA, current authority, company locking, forced tenant RLS and transactional audit cover the new endpoints. Templates are not live roles: creation/archive never changes existing member grants. Invitation provisioning and live role assignments remain pending.

CI run [37977086145](https://github.com/Shakeelnasafian/HR-System/actions/runs/37977086145), commit `2383ccff7c54bfb605c22f96e5f5036d8e924d67`, passed 31 backend tests / 209 assertions, 5 frontend tests and the Docker browser journey including create/copy/archive and preserved member access. Local build and lint passed. The following evidence-only update waits for the member-list reload before screenshot capture and records an expanded active template. Latest exact-commit checks remain on PR #5.
