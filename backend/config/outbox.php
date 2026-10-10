<?php

use App\Services\Messaging\Handlers\InvitationSendHandler;

return [
    // type => class implementing App\Services\Messaging\OutboxHandler. Unknown types fail permanently on their first claim.
    'handlers' => ['invitation.send' => InvitationSendHandler::class],
    'queue' => env('OUTBOX_QUEUE'),
    'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 8),
    // Retry delay: min(backoff_max, backoff_base * 2^(attempt-1)) seconds.
    'backoff_base' => (int) env('OUTBOX_BACKOFF_BASE', 10),
    'backoff_max' => (int) env('OUTBOX_BACKOFF_MAX', 3600),
    // Must exceed queue latency plus handler run time; an expired lease is re-claimed (at-least-once delivery).
    'lease_seconds' => (int) env('OUTBOX_LEASE_SECONDS', 300),
    // A job may start prepare only while its lease outlives this margin (seconds); keep it well below lease_seconds.
    'prepare_margin' => (int) env('OUTBOX_PREPARE_MARGIN', 30),
    // TenantContext::runSystem opt-in: set HR_SYSTEM_CONTEXT=true only on queue worker and scheduler processes, never web.
    'system_context' => (bool) env('HR_SYSTEM_CONTEXT', false),
    'batch' => (int) env('OUTBOX_BATCH', 50),
    'max_per_tenant' => (int) env('OUTBOX_MAX_PER_TENANT', 500),
];
