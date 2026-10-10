<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Tenant-less authentication events. Runtime may only INSERT; never stores attempted emails, passwords, codes or tokens. */
final class SecurityEvents
{
    public static function record(string $event, mixed $userId = null, ?string $identifier = null): void
    {
        try {
            $request = app()->bound('request') ? request() : null;
            $agent = $request ? Str::limit((string) $request->userAgent(), 255, '') : '';
            // Nested calls become a savepoint, so a failed write cannot poison an outer transaction.
            DB::transaction(fn () => DB::table('security_events')->insert([
                'id' => (string) Str::uuid(), 'user_id' => is_numeric($userId) ? (int) $userId : null, 'event' => $event,
                'identifier_hash' => $identifier === null || trim($identifier) === '' ? null : self::hash($identifier),
                'ip' => $request?->ip(), 'user_agent' => $agent === '' ? null : $agent,
                'correlation_id' => app(RequestId::class)->current(),
            ]));
        } catch (Throwable $e) {
            // Authentication must not fail because the security log is unavailable, but the gap must be visible.
            Log::error('Security event could not be recorded.', ['event' => $event, 'exception' => $e::class, 'message' => $e->getMessage()]);
        }
    }

    /** Keyed with a purpose-derived subkey so a dictionary of known emails cannot reverse it and the raw app key is not reused. */
    public static function hash(string $identifier): string
    {
        return hash_hmac('sha256', Str::lower(trim($identifier)), hash_hmac('sha256', 'security-events.identifier', (string) config('app.key'), true));
    }
}
