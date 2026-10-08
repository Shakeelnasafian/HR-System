<?php
namespace App\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Per-request/job context. Never serialize models into a tenant job. */
final class TenantContext
{
    private ?string $tenantId = null;
    private ?int $userId = null;

    public function run(string $tenantId, int $userId, Closure $work): mixed
    {
        if ($this->tenantId !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('A tenant boundary must start outside any existing transaction.');
        }
        abort_unless(Str::isUuid($tenantId), 400, 'Invalid tenant selector.');
        try {
            return DB::transaction(function () use ($tenantId, $userId, $work) {
                // Bootstrap tables contain membership/service state, never employee data.
                $membership = DB::table('tenant_memberships as m')->join('tenants as t', 't.id', '=', 'm.tenant_id')
                    ->where('m.tenant_id', $tenantId)->where('m.user_id', $userId)
                    ->where('m.status', 'active')->where('t.status', 'active')
                    ->select('m.id', 'm.requires_mfa')->first();
                abort_unless($membership, 403, 'Tenant access unavailable.');
                DB::select("select set_config('app.tenant_id', ?, true)", [$tenantId]);
                $this->tenantId = $tenantId;
                $this->userId = $userId;
                return $work($membership);
            });
        } finally {
            $this->tenantId = null;
            $this->userId = null;
        }
    }
    public function id(): string { return $this->tenantId ?? throw new LogicException('Missing tenant context.'); }
    public function userId(): int { return $this->userId ?? throw new LogicException('Missing tenant principal.'); }
}
