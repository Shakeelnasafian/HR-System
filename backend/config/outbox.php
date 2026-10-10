<?php
return [
    // type => class implementing App\Messaging\OutboxHandler. Unknown types fail permanently on their first claim.
    'handlers' => [],
    'queue' => env('OUTBOX_QUEUE'),
    'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 8),
    // Retry delay: min(backoff_max, backoff_base * 2^(attempt-1)) seconds.
    'backoff_base' => (int) env('OUTBOX_BACKOFF_BASE', 10),
    'backoff_max' => (int) env('OUTBOX_BACKOFF_MAX', 3600),
    // Must exceed queue latency plus handler run time; an expired lease is re-claimed (at-least-once delivery).
    'lease_seconds' => (int) env('OUTBOX_LEASE_SECONDS', 300),
    'batch' => (int) env('OUTBOX_BATCH', 50),
    'max_per_tenant' => (int) env('OUTBOX_MAX_PER_TENANT', 500),
];
