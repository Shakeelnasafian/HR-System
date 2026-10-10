<?php
namespace App\Messaging;

/**
 * Delivery is at-least-once: use $event->id as the idempotency key toward every receiver.
 * $event: id, tenant_id, company_id, type, payload (array of scalars), actor_id, correlation_id, attempt.
 */
interface OutboxHandler
{
    /** Runs inside a short system tenant transaction (RLS on, no user principal). Reload and re-authorize current data;
     *  return null to cancel (e.g. recipient revoked). Do no external I/O here. */
    public function prepare(object $event): mixed;

    /** Runs outside any database transaction and tenant context. Throw to retry with backoff. */
    public function deliver(object $event, mixed $prepared): void;
}
