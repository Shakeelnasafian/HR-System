# Foundation development runbook

This is a local development stack, not a production deployment configuration. Bindings are loopback-only. Use synthetic accounts and company data.

## Start locally

Prerequisites: Docker Engine with Compose v2. Node/PHP are built in the containers. Checkout `feat/api-spa-foundation` (PR #2 is stacked on documentation PR #1).

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

3. Open `http://localhost:8080`. Sign in, select Demo Group, confirm your password and enroll an authenticator. Save recovery codes, sign out and sign in again with MFA. Company cards should then appear.

The API, worker and scheduler receive only runtime database credentials. Only the migrate service receives owner credentials; PostgreSQL initialization receives the separate administrator credentials. Migrations own the tables, and the runtime account is neither owner nor BYPASSRLS. Initialization scripts execute only for a new database volume. Changing `.env` passwords does not rotate an existing database role automatically.

## Development and tests

Frontend local checks:

```sh
cd frontend
npm ci
npm run build
npm test
```

Backend CI uses PHP 8.5, PostgreSQL 18, Redis 8, migrations under a separate owner, and `infra/postgres/test-runtime.sql` for runtime grants. `vendor/bin/phpunit` must use a disposable database: its fixtures TRUNCATE synthetic test tables. Never point the test environment at a populated development or production database. The test admin credentials are only for fixture setup; security assertions use `hr_app`.

The browser job builds the actual Docker stack, initializes a demo tenant and exercises CSRF, cookies, MFA enrollment/challenge, company access, logout and mobile layout through Nginx. Browser traces/screenshots contain synthetic data only.

For frontend hot reload use `npm run dev` with a Laravel development server at port 8000 and matching Sanctum stateful origin configuration. Use the Docker gateway for complete authentication/deep-link flows. The shipped single-origin setup is the supported foundation test topology.

## Operations

- `docker compose logs api worker scheduler` inspects local process logs. Local password reset mail uses the log mailer; keep those logs private.
- `/up` is Laravel's application boot health check, not a full database/queue/readiness guarantee.
- Apply schema changes with the migrate service, then restart app/worker/scheduler processes on release.
- `docker compose down` stops this project while preserving its volumes. Do not use `down -v` on data you intend to retain. CI removes its disposable volumes at the end of the job.
- No public registration, user-management UI, HR records, document upload or payroll functionality is included yet. Provisioning is limited to the explicit local demo command.

## Before production

Complete foundation authorization administration, audit, private-file quarantine, outbox, monitoring, backups/restore, invitation lifecycle and policy review. Choose production hosting/secrets/mail, HTTPS cookies and origin settings. Pin reviewed image digests, scan dependencies and resolve redistribution/license requirements. Redis 8 images are used unmodified for local development/CI; the commercial deployment/licensing decision remains open. Never reuse CI credentials, APP_KEY or demo data.
