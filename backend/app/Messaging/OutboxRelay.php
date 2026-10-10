<?php
namespace App\Messaging;
use App\Jobs\DeliverOutboxEvent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
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

    /** SKIP LOCKED + lease: concurrent relays never claim the same attempt. Each claim opens a visible attempt row. */
    public function claim(string $tenant, int $limit): array
    {
        return app(TenantContext::class)->runSystem($tenant, fn () => DB::select("WITH due AS (
                SELECT id FROM outbox_events WHERE tenant_id = ? AND status = 'pending' AND available_at <= clock_timestamp()
                  AND (lease_until IS NULL OR lease_until <= clock_timestamp()) ORDER BY available_at, seq LIMIT ? FOR UPDATE SKIP LOCKED),
              claimed AS (UPDATE outbox_events e SET attempts = e.attempts + 1, lease_until = clock_timestamp() + make_interval(secs => ?)
                FROM due WHERE e.id = due.id RETURNING e.tenant_id, e.id, e.attempts)
            INSERT INTO outbox_attempts (tenant_id, event_id, attempt) SELECT tenant_id, id, attempts FROM claimed RETURNING event_id, attempt",
            [$tenant, $limit, (int) config('outbox.lease_seconds')]));
    }
}
