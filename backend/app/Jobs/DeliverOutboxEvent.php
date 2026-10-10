<?php

namespace App\Jobs;

use App\Services\Messaging\OutboxDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Scalar identifiers only; the event is reloaded under system tenant context. Retries are owned by the outbox, not the queue. */
final class DeliverOutboxEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $tenantId, public string $eventId, public int $attempt) {}

    public function handle(OutboxDelivery $delivery): void
    {
        $delivery->run($this->tenantId, $this->eventId, $this->attempt);
    }
}
