<?php
namespace App\Messaging;
use App\Jobs\DeliverOutboxEvent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Leases due events per active tenant in short transactions and dispatches delivery jobs only after each claim commits. */
final class OutboxRelay
{
    /** @return int number of events claimed and dispatched */
    public function run(?int $batch = null, ?int $maxPerTenant = null): int
    {
        $batch = max(1, $batch ?? (int) config('outbox.batch')); $max = max(1, $maxPerTenant ?? (int) config('outbox.max_per_tenant'));
        $total = 0;
        foreach (DB::table('tenants')->where('status', 'active')->orderBy('id')->pluck('id') as $tenant) {
            // One tenant's failure (suspended mid-run, queue outage) must not starve the others; claimed-but-undispatched
            // events are re-claimed after their lease expires.
            $done = 0;
            try {
                while ($done < $max) {
                    $claimed = $this->claim($tenant, min($batch, $max - $done));
                    foreach ($claimed as $row) {
                        DeliverOutboxEvent::dispatch($tenant, $row->event_id, (int) $row->attempt)->onQueue(config('outbox.queue'));
                    }
                    $done += count($claimed);
                    if (count($claimed) < $batch) { break; }
                }
            } catch (Throwable $e) {
                report($e);
            }
            $total += $done;
        }
        return $total;
    }

    /**
     * SKIP LOCKED + lease: concurrent relays never claim the same attempt. Each claim opens a visible attempt row.
     * An attempt whose lease expired without an ack (worker killed, timeout, lost dispatch) is closed as `abandoned`;
     * when no attempts remain the event fails and alerts instead of being re-claimed forever.
     */
    public function claim(string $tenant, int $limit): array
    {
        $max = max(1, (int) config('outbox.max_attempts'));
        [$spent, $claimed] = app(TenantContext::class)->runSystem($tenant, fn () => [
            DB::select("WITH spent AS (
                    SELECT id FROM outbox_events WHERE tenant_id = ? AND status = 'pending' AND attempts >= ?
                      AND (lease_until IS NULL OR lease_until <= clock_timestamp()) FOR UPDATE SKIP LOCKED),
                  failed AS (UPDATE outbox_events e SET status = 'failed', lease_until = NULL, last_error = 'Attempts exhausted without acknowledgement.'
                    FROM spent WHERE e.id = spent.id RETURNING e.id, e.type),
                  closed AS (UPDATE outbox_attempts a SET finished_at = clock_timestamp(), outcome = 'abandoned', error = 'Lease expired without acknowledgement.'
                    FROM failed WHERE a.event_id = failed.id AND a.finished_at IS NULL)
                SELECT id, type FROM failed", [$tenant, $max]),
            DB::select("WITH due AS (
                    SELECT id FROM outbox_events WHERE tenant_id = ? AND status = 'pending' AND attempts < ? AND available_at <= clock_timestamp()
                      AND (lease_until IS NULL OR lease_until <= clock_timestamp()) ORDER BY available_at, seq LIMIT ? FOR UPDATE SKIP LOCKED),
                  claimed AS (UPDATE outbox_events e SET attempts = e.attempts + 1, lease_until = clock_timestamp() + make_interval(secs => ?)
                    FROM due WHERE e.id = due.id RETURNING e.tenant_id, e.id, e.attempts),
                  closed AS (UPDATE outbox_attempts a SET finished_at = clock_timestamp(), outcome = 'abandoned', error = 'Lease expired without acknowledgement.'
                    FROM due WHERE a.event_id = due.id AND a.finished_at IS NULL)
                INSERT INTO outbox_attempts (tenant_id, event_id, attempt) SELECT tenant_id, id, attempts FROM claimed RETURNING event_id, attempt",
                [$tenant, $max, $limit, (int) config('outbox.lease_seconds')]),
        ]);
        foreach ($spent as $event) {
            Log::critical('Outbox event failed permanently.', ['tenant_id'=>$tenant, 'event_id'=>$event->id, 'type'=>$event->type]);
        }
        return $claimed;
    }
}
