# Dependency and reuse evaluation

Reviewed: 7 October 2026. No packages were installed. These are candidate version families, not a resolved, tested or vulnerability-cleared dependency set. Exact patches, release tags, image digests, extension compatibility and transitive licenses must be verified when implementation is approved.

## Reuse before custom code

| Option | Evidence and fit | Recommendation / unresolved cost |
|---|---|---|
| Existing HR-System functionality | Repository is empty | Nothing to reuse locally |
| Company Tools | Source not supplied or inspected | Review authentication, tenancy and Docker patterns if provided; do not assume safe reuse |
| Frappe HR | Official repository lists employee lifecycle, leave, attendance and payroll; Python/JavaScript framework and Vue UI; GPL-3.0 [S8] | Credible alternative if operational HR delivery matters more than owning a Laravel/React product. No fit-gap prototype or isolation audit performed. Different stack and product customization/upgrade obligations need evaluation |
| Laravel built-ins / first-party packages | Authentication, authorization and infrastructure capabilities documented by Laravel [S1–S4] | Reuse these; do not write password hashing, MFA algorithms or queue infrastructure |
| Spatie permissions | Main-branch manifest accepts Laravel 12/13 and PHP 8.3+, declares MIT [S9] | Candidate for permission catalog/role bundles. Stable release and scoped-assignment fit are unverified; do not install a dev branch merely because its manifest accepts Laravel 13 |
| Custom domain logic | Requirements demand effective employment history, company-specific grants, transfer settlement and ledger invariants | Justified for those product rules if custom build is approved; generic infrastructure remains reused |

A custom build is a product-control choice, not an established claim of lower cost. It carries ongoing security, policy-calculation, test and operations ownership. Frappe HR may shorten a conventional HR rollout; repository documentation alone does not prove whether its behavior satisfies the specified company/history boundaries. No product was deployed for comparison.

## Proposed baseline

| Component | Candidate | Evidence / license status | Adoption gate |
|---|---|---|---|
| PHP | 8.5.x | Official active support through December 2027, security through December 2029 [S5]; exact runtime license to inventory | Confirm PDO PostgreSQL, Redis client and image compatibility |
| Laravel | 13.x | Official PHP range 8.3–8.5, security support to March 2028 [S1]; framework metadata declares MIT [S10] | Resolve stable patch and lock dependencies |
| SPA | React 19.x + TypeScript + Vite | React documents Vite as a from-scratch build option [S11]; package versions to resolve, individual licenses to inventory | Standalone React build; no Laravel React/Inertia starter kit |
| Routing | React Router, candidate | Evaluate stable package/React compatibility and license during scaffold | Client-side routing and route-level lazy loading; not yet selected/installed |
| Server-state cache | TanStack Query, candidate | Detailed compatibility/license review still pending | Adopt only if it simplifies tenant-aware cache invalidation versus existing SPA state; not required for the spike |
| Node build runtime | 24.x LTS | Official release page lists 24 as LTS and 26 as Current at review [S6] | Check selected Vite engine range; Node used for build tools only |
| PostgreSQL | 18.x | Official supported major, support listed to November 2030 [S7]; PostgreSQL license to inventory with image | Pin current supported patch/digest and validate extensions/RLS |
| Redis | Supported 8.x release | Redis 8 offers AGPLv3, RSALv2 or SSPLv1 choices [S12] | Record chosen licensing basis and deployment fit; do not assume BSD licensing |
| Auth | Sanctum + Fortify stable Laravel-13-compatible releases | Official SPA cookie flow and headless authentication [S2,S3]; individual package licenses to inventory | Resolve manifests and prove CSRF, session expiry and mandatory MFA |
| Queue operations | Laravel queues; Horizon optional | First-party Horizon documentation [S4] | Check stable package and Redis compatibility; dashboard restricted to operators |
| Test/tooling | PHPUnit, Laravel Pint, TypeScript checks; browser runner to select | Prefer compatible established tools over a custom harness | Resolve stable versions and licenses in implementation |
| Files | Laravel filesystem abstraction; private local disk in development | Provider and malware scanner not selected | Quarantine must stay closed on scan failure; production backup/storage cost unresolved |

The official Laravel React starter kit was inspected. It couples React to Inertia and is therefore **not selected** following the owner's SPA/API clarification. Reuse Fortify independently rather than copying the coupled frontend. Exact third-party SPA dependency choices should be small and confirmed against maintained releases, not accumulated speculatively.

No application license is created by choosing dependencies. Before distributing images, produce a dependency/license inventory, retain required notices, examine security advisories and record the actual chosen versions. Redis's license options are a concrete decision to resolve; this document makes no legal compatibility determination.

## Maintenance and cost ownership

Proposed backend owner maintains PHP/Laravel/auth/queue upgrades; frontend owner maintains React/router/build dependencies; operations owner maintains PostgreSQL/Redis/images, backups, scanner and monitoring. The project owner must assign real people before pilot. Use lockfiles and pinned image digests, planned upgrade reviews and prompt advisory triage. Test upgrades against tenant isolation and financial-like leave invariants before release.

Hosting, private files/backups, mail, malware scanning, CI and monitoring have unpriced operating costs. This plan does not require paid auth, AI or a commercial admin theme. Performance has not been measured; introduce no cache server beyond the planned Redis services until a need is demonstrated. Consider separate Redis queue/cache instances if eviction or workload contention makes sharing unsafe.

## Official sources

- S1: [Laravel 13 release and support policy](https://laravel.com/docs/13.x/releases).
- S2: [Sanctum SPA authentication](https://laravel.com/docs/13.x/sanctum).
- S3: [Fortify headless authentication](https://laravel.com/framework/docs/13.x/fortify).
- S4: [Horizon](https://laravel.com/docs/13.x/horizon).
- S5: [PHP supported versions](https://www.php.net/supported-versions.php).
- S6: [Node.js release status](https://nodejs.org/en/about/previous-releases).
- S7: [PostgreSQL version policy](https://www.postgresql.org/support/versioning/).
- S8: [Frappe HR official repository](https://github.com/frappe/hrms).
- S9: [Spatie permission manifest](https://github.com/spatie/laravel-permission/blob/main/composer.json). Main branch was inspected, not a pinned stable release.
- S10: [Laravel framework repository](https://github.com/laravel/framework).
- S11: [React from-scratch guidance](https://react.dev/learn/build-a-react-app-from-scratch) and [Vite guide](https://vite.dev/guide/).
- S12: [Redis license table](https://redis.io/legal/licenses/).
- S13: [Laravel starter kits](https://laravel.com/docs/13.x/starter-kits), [inspected Composer manifest](https://github.com/laravel/react-starter-kit/blob/main/composer.json) and [frontend manifest](https://github.com/laravel/react-starter-kit/blob/main/package.json).

These are live URLs, not immutable dependency provenance. Implementation must record selected release tags and lockfile hashes. Documentation verification is not an installation, runtime compatibility test or security audit.
