# Foundation development runbook

This is a local development stack, not a production deployment configuration. Bindings are loopback-only. Use synthetic accounts and company data.

## Start locally

Prerequisites: Docker Engine with Compose v2. Node/PHP are built in the containers. Foundation, workforce and permission administration are on `main` (PRs #3 and #4). Permission bundles were merged only into `feat/company-permissions` (PR #5) and are being landed on main through `feat/v1-i0-bundles-housekeeping`.

1. Copy the root `.env.example` to `.env`. Set independent random values for `POSTGRES_PASSWORD`, `OWNER_DB_PASSWORD` and `APP_DB_PASSWORD`. Set `APP_KEY` to `base64:` followed by a base64 encoding of 32 cryptographically random bytes. These values must remain local. `openssl rand -base64 32` can generate the random material.
2. Build and initialize:

```sh
docker compose build api web
docker compose up -d postgres redis
docker compose run --rm migrate
docker compose run --rm migrate php artisan hr:demo
docker compose up -d api web worker scheduler
```

The demo command asks for an email and hidden password of at least 12 characters. It creates one tenant, two companies and a membership requiring MFA. It refuses to overwrite an existing user and only runs in `local`. CI uses the optional `--email` and `--password-env` inputs with a synthetic password; never pass a real password as a command-line argument.

3. Open `http://localhost:8080`. Sign in, select Demo Group, confirm your password and enroll an authenticator. Save recovery codes, sign out and sign in again with MFA. Company cards should then appear. Open a company to manage its organization, add a synthetic employee, activate/end an employment, create a rehire draft, or inspect audit history. The demo grants explicit organization/workforce/audit permissions in both demo companies.

The API, worker and scheduler receive only runtime database credentials. Only the migrate service receives owner credentials; PostgreSQL initialization receives the separate administrator credentials. Migrations own the tables, and the runtime account is neither owner nor BYPASSRLS. Initialization scripts execute only for a new database volume. Changing `.env` passwords does not rotate an existing database role automatically.

## Development and tests

Frontend local checks:

```sh
cd frontend
npm ci
npm run build
npm test
npm run lint
```

Backend CI uses PHP 8.5, PostgreSQL 18, Redis 8, migrations under a separate owner, and `infra/postgres/test-runtime.sql` for runtime grants. `vendor/bin/phpunit` must use a disposable database: its fixtures TRUNCATE synthetic test tables. Never point the test environment at a populated development or production database. The test admin credentials are only for fixture setup; security assertions use `hr_app`.

The browser job builds the actual Docker stack, initializes a demo tenant and exercises CSRF, cookies, MFA enrollment/challenge, company access, organization creation, employee creation/activation/ending, audit history, logout and mobile layout through Nginx. Browser traces/screenshots contain synthetic data only.

For frontend hot reload use `npm run dev` with a Laravel development server at port 8000 and matching Sanctum stateful origin configuration. Use the Docker gateway for complete authentication/deep-link flows. The shipped single-origin setup is the supported foundation test topology.

## Operations

- `docker compose logs api worker scheduler` inspects local process logs. Local password reset mail uses the log mailer; keep those logs private.
- `/up` is Laravel's application boot health check, not a full database/queue/readiness guarantee.
- Apply schema changes with the migrate service, then restart app/worker/scheduler processes on release.
- `docker compose down` stops this project while preserving its volumes. Do not use `down -v` on data you intend to retain. CI removes its disposable volumes at the end of the job.
- No public registration, invitation/membership lifecycle UI, document upload or payroll functionality is included yet. Initial organization and workforce records are available. Provisioning is limited to the explicit local demo command.

## Before production

Complete invitation/role administration, full audit coverage, private-file quarantine, outbox, monitoring, backups/restore, invitation lifecycle and policy review. Choose production hosting/secrets/mail, HTTPS cookies and origin settings. Pin reviewed image digests, scan dependencies and resolve redistribution/license requirements. Redis 8 images are used unmodified for local development/CI; the commercial deployment/licensing decision remains open. Never reuse CI credentials, APP_KEY or demo data.

## Existing development databases

Rebuild the application images, run `docker compose run --rm migrate`, then restart services. The migration command also reapplies explicit runtime grants. The workforce migration adds six tables; it does not alter existing employee data because earlier versions did not contain employee tables. Migration rollback drops the new tables and their history, so do not use rollback after populating data; use a reviewed forward migration or restore instead.

Existing demo accounts created before workforce grants were added are intentionally not overwritten by `hr:demo`. An owner can grant `organization.read`, `organization.write`, `workforce.read`, `workforce.write` and `audit.read` explicitly to the intended membership/company tuples using the owner connection. Do not grant every tenant member these permissions or expose the owner connection to the application. New synthetic demo accounts include these grants automatically.

Employment end dates are exclusive: enter the day after the final working day. Activation cannot start a future-dated draft or overlap an existing active/ended interval. Rehire creates a new employment for the same employee; it never replaces history. Transfers and entitlement movement are not supported yet.

## Permission administration demo

The owner-run `hr:demo` now grants the synthetic administrator the explicit `access.manage` permission. Pass `--colleague` to add Demo Colleague with company-read-only access and an unknown random password; this extra account exists solely for local permission review demonstrations. No invitation email is sent. Existing demo users are never overwritten.

After signing in with MFA, open a company → Permissions. Select Demo Colleague, adjust permissions, enter a reason, review the preview and apply. You cannot change your own grants. The target must require MFA before receiving privileged permissions. A stale version requires closing/reloading and reviewing the latest grants. Removing every permission removes the target from this company list; reattachment currently requires explicit owner provisioning.

The access migration adds a company version column; no additional runtime database privileges or package dependencies are introduced. The shared audit class moved from Workforce to Audit without changing the audit table or historical records.
