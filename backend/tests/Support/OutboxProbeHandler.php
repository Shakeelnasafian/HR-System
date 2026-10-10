<?php
namespace Tests\Support;
use App\Messaging\OutboxHandler;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Test-only handler. payload: mode (ok|throw|cancel|crash-once), path (JSONL file standing in for the receiver). */
final class OutboxProbeHandler implements OutboxHandler
{
    public function prepare(object $event): mixed
    {
        if ($event->payload['mode'] === 'cancel') { return null; }
        return ['tenant'=>app(TenantContext::class)->id(), 'companies'=>DB::table('companies')->count()];
    }
    public function deliver(object $event, mixed $prepared): void
    {
        if ($event->payload['mode'] === 'throw') { throw new \RuntimeException('Receiver rejected alice@example.test'); }
        try { app(TenantContext::class)->id(); $context = true; } catch (\LogicException) { $context = false; }
        $first = ! file_exists($event->payload['path']);
        file_put_contents($event->payload['path'], json_encode($prepared + ['event'=>$event->id, 'attempt'=>$event->attempt, 'context'=>$context,
            'outside'=>DB::table('companies')->count(), 'level'=>DB::transactionLevel(), 'pid'=>getmypid()])."\n", FILE_APPEND);
        if ($event->payload['mode'] === 'crash-once' && $first) { exit(3); } // dies after the receiver saw it, before the ack
    }
}
