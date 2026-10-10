<?php

namespace App\Services\Messaging;

use App\Services\Audit\RequestId;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/** Transactional outbox: events commit or roll back with the domain change that records them. */
final class Outbox
{
    /**
     * Payload values must be scalar identifiers/flags (or null): no names, contact data or nested structures, because
     * handlers reload authorized data when they run. Idempotent per (tenant, dedupe_key); returns the event id.
     * Re-recording a key with a different payload silently keeps the original; a different type throws.
     */
    public static function record(string $type, array $payload, string $dedupeKey, ?string $company = null): string
    {
        $context = app(TenantContext::class);
        $tenant = $context->id();
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Outbox events must be recorded inside the tenant transaction.');
        }
        if (! preg_match('/^[a-z][a-z0-9_.-]{0,79}$/', $type)) {
            throw new InvalidArgumentException('Invalid outbox event type.');
        }
        if ($dedupeKey === '' || strlen($dedupeKey) > 200) {
            throw new InvalidArgumentException('Invalid outbox dedupe key.');
        }
        foreach ($payload as $key => $value) {
            if (! is_string($key) || ! ($value === null || is_scalar($value))) {
                throw new InvalidArgumentException('Outbox payload must map names to scalar values.');
            }
        }
        $id = (string) Str::uuid();
        $inserted = DB::select('INSERT INTO outbox_events (id, tenant_id, company_id, type, payload, dedupe_key, actor_id, correlation_id)
            VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?) ON CONFLICT (tenant_id, dedupe_key) DO NOTHING RETURNING id', [
            $id, $tenant, $company, $type, json_encode((object) $payload, JSON_THROW_ON_ERROR), $dedupeKey,
            $context->isSystem() ? null : $context->userId(), app(RequestId::class)->current(),
        ]);
        if ($inserted) {
            return $id;
        }
        // A replay keeps the first event as recorded: a differing payload/company is ignored, a differing type is a key collision.
        $existing = DB::table('outbox_events')->where('tenant_id', $tenant)->where('dedupe_key', $dedupeKey)->select('id', 'type')->first();
        if ($existing->type !== $type) {
            throw new LogicException('Outbox dedupe key already used for another event type.');
        }

        return $existing->id;
    }
}
