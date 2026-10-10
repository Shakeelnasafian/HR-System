# Transactional outbox contract (I2)

Implements backlog F08 and architecture decision A05: domain changes, audit entries and outbox events commit together; workers deliver after commit; delivery is at-least-once and receivers deduplicate. There is no handler registered yet; invitations (I3) are the first consumer.

## Recording events

Call `Outbox::record(type, payload, dedupeKey, ?companyId)` inside the same tenant transaction as the domain change. It fails outside a tenant context or transaction, and it commits or rolls back with the change. A repeated `(tenant, dedupeKey)` returns the existing event ID; reusing a key for a different `type` throws. Payload values must be scalars or null (resource IDs, flags). Never put names, contact data, tokens or nested structures in a payload; handlers reload what they need.

## Handlers

Implement `App\Messaging\OutboxHandler` and register it in `config/outbox.php` under `handlers` (type => class). Unknown types fail.

- `prepare($event)` runs in a short **system tenant transaction**: RLS applies, there is no user principal, so `CompanyAccess` and user-attributed audit fail closed. Reload and re-check current data here (for example, that an invitation is still pending), do no external I/O, and return null to cancel.
- `deliver($event, $prepared)` runs outside any transaction or tenant context. Throw to retry. Always pass `$event->id` to the receiver as its idempotency key.

Statuses: `pending`, `delivered`, `cancelled`, `failed`. An event fails after `OUTBOX_MAX_ATTEMPTS` (default 8) or for an unknown type, with a `Log::critical` naming only tenant, event ID and type. Stored errors contain the exception class only.

## Relay, leases and crashes

The scheduler runs `hr:outbox-relay` every five seconds (`withoutOverlapping`, `onOneServer`; requires the Redis cache lock store). For each active tenant it claims due events with `FOR UPDATE SKIP LOCKED`, sets a lease (`OUTBOX_LEASE_SECONDS`, default 300, which must exceed queue latency plus handler time), increments `attempts`, writes an `outbox_attempts` row and dispatches `DeliverOutboxEvent` with scalar IDs. A job only acts if the event is still leased to its attempt; prepare also requires `OUTBOX_PREPARE_MARGIN` (default 30) seconds of lease remaining. A worker crash or timeout leaves the attempt open; after lease expiry the relay closes it as `abandoned` and re-claims, or marks the event `failed` with a critical log when attempts are exhausted. Backoff is exponential (`OUTBOX_BACKOFF_BASE`, `OUTBOX_BACKOFF_MAX`) without jitter. Suspended tenants' events stay pending until reactivation.

`hr:outbox-status` prints counts and the oldest pending age per active tenant, without payloads. Scheduled relay output and stderr logs, including critical alerts, are appended to the scheduler container log (`SCHEDULE_OUTPUT`, default `/proc/1/fd/2`) instead of Laravel's default `/dev/null`. There is no HTTP view yet, and scheduler liveness monitoring belongs to F09.

## System tenant context

`TenantContext::runSystem()` is the only way to enter a tenant without a user. It requires a console process, no active tenant context or transaction, an active tenant, and `HR_SYSTEM_CONTEXT=true`, which `compose.yaml` sets only on the worker and scheduler services. The API runs under PHP-FPM; Octane or forcing `APP_RUNNING_IN_CONSOLE` is unsupported without revisiting this guard.

## Data and privileges

`outbox_events` and `outbox_attempts` use FORCE RLS with composite tenant foreign keys. The runtime role has SELECT, INSERT and UPDATE, never DELETE or TRUNCATE, so delivery history is retained. Retention of delivered events requires an owner decision.
