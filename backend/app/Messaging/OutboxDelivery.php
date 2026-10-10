<?php

namespace App\Messaging;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * prepare (short system transaction) → deliver (no transaction, external I/O) → ack (short system transaction).
 * Every step acts only while the event is still pending and leased to this attempt, so stale or duplicate jobs are no-ops.
 */
final class OutboxDelivery
{
    public function __construct(private TenantContext $context) {}

    /** @return string delivered|retry|failed|cancelled|stale */
    public function run(string $tenant, string $eventId, int $attempt): string
    {
        $prepared = null;
        try {
            [$event, $handler, $prepared] = $this->context->runSystem($tenant, function () use ($eventId, $attempt) {
                $event = $this->current($eventId, $attempt, false);
                if (! $event) {
                    return [null, null, null];
                }
                $class = config('outbox.handlers')[$event->type] ?? null; // types contain dots: no config() dot lookup
                if (! is_string($class) || ! is_subclass_of($class, OutboxHandler::class)) {
                    return [$event, null, null];
                }
                $handler = app($class);

                return [$event, $handler, $handler->prepare($event)];
            });
        } catch (Throwable $e) {
            return $this->finish($tenant, $eventId, $attempt, $e);
        }
        if (! $event) {
            return 'stale';
        }
        if (! $handler) {
            return $this->finish($tenant, $eventId, $attempt, 'failed', 'Unknown outbox event type.');
        }
        if ($prepared === null) {
            return $this->finish($tenant, $eventId, $attempt, 'cancelled');
        }
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Outbox delivery must run outside database transactions.');
        }
        try {
            $handler->deliver($event, $prepared);
        } catch (Throwable $e) {
            return $this->finish($tenant, $eventId, $attempt, $e);
        }

        return $this->finish($tenant, $eventId, $attempt, 'delivered');
    }

    /**
     * The event only while it is still pending and leased for this attempt. Prepare additionally needs the lease to outlive
     * a safety margin, so a job that sat in the queue past its lease cannot deliver concurrently with a re-claim.
     * The ack (locked) accepts an expired lease as long as no newer attempt has claimed the event.
     */
    private function current(string $eventId, int $attempt, bool $ack): ?object
    {
        $q = DB::table('outbox_events')->where('tenant_id', $this->context->id())->where('id', $eventId)->where('status', 'pending')
            ->where('attempts', $attempt)->whereNotNull('lease_until')
            ->select('id', 'tenant_id', 'company_id', 'type', 'payload', 'actor_id', 'correlation_id', 'attempts as attempt');
        $event = ($ack ? $q->lockForUpdate() : $q->whereRaw('lease_until > clock_timestamp() + make_interval(secs => ?)', [max(0, (int) config('outbox.prepare_margin'))]))->first();
        if ($event) {
            $event->payload = json_decode($event->payload, true, 4, JSON_THROW_ON_ERROR);
        }

        return $event;
    }

    private function finish(string $tenant, string $eventId, int $attempt, string|Throwable $outcome, ?string $error = null): string
    {
        if ($outcome instanceof Throwable) {
            // Exception messages may echo receiver or personal data: only the class is stored or logged.
            $error = substr($outcome::class, 0, 500);
            $outcome = $attempt >= (int) config('outbox.max_attempts') ? 'failed' : 'retry';
        }
        $type = null;
        $result = $this->context->runSystem($tenant, function () use ($eventId, $attempt, $outcome, $error, &$type) {
            $event = $this->current($eventId, $attempt, true);
            if (! $event) {
                return 'stale';
            }
            $type = $event->type;
            $now = DB::raw('clock_timestamp()');
            $update = ['lease_until' => null, 'last_error' => $error, 'status' => $outcome === 'retry' ? 'pending' : $outcome];
            if ($outcome === 'delivered') {
                $update['delivered_at'] = $now;
            }
            if ($outcome === 'retry') {
                $delay = min((int) config('outbox.backoff_max'), (int) config('outbox.backoff_base') * 2 ** min($attempt - 1, 30));
                $update['available_at'] = DB::raw('clock_timestamp() + make_interval(secs => '.(int) $delay.')');
            }
            DB::table('outbox_events')->where('id', $eventId)->update($update);
            DB::table('outbox_attempts')->where('event_id', $eventId)->where('attempt', $attempt)->whereNull('finished_at')
                ->update(['finished_at' => $now, 'outcome' => $outcome, 'error' => $error]);

            return $outcome;
        });
        if ($result === 'failed') {
            Log::critical('Outbox event failed permanently.', ['tenant_id' => $tenant, 'event_id' => $eventId, 'type' => $type]);
        } elseif ($result === 'retry') {
            Log::warning('Outbox delivery attempt failed; retry scheduled.', ['event_id' => $eventId, 'type' => $type, 'attempt' => $attempt, 'error' => $error]);
        }

        return $result;
    }
}
