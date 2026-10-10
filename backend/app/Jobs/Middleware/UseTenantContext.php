<?php

namespace App\Jobs\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use InvalidArgumentException;

final class UseTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        if (! isset($job->tenantId, $job->actorId) || ! is_string($job->tenantId) || ! is_int($job->actorId)) {
            throw new InvalidArgumentException('Tenant jobs require scalar tenantId and actorId.');
        }

        return app(TenantContext::class)->run($job->tenantId, $job->actorId, fn () => $next($job));
    }
}
