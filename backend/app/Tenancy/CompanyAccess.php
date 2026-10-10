<?php
namespace App\Tenancy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CompanyAccess
{
    public function readable(string $permission = 'company.read'): Builder
    {
        $context = app(TenantContext::class);
        $grants = fn (Builder $q) => $q->from('company_grants as g')->join('tenant_memberships as m', 'm.id', '=', 'g.membership_id')
            ->where('g.tenant_id', $context->id())->where('m.user_id', $context->userId())->where('m.status', 'active')->where('g.permission', $permission);
        // Privileged permissions are usable only from an MFA-verified session, whatever the membership flag says.
        // Holders get 403 before any resource is resolved (no per-company signal); non-holders keep the normal 404 path.
        // Sessionless contexts (jobs, console) are never verified, so they fail closed here.
        if (PermissionCatalog::requiresMfa([$permission]) && ! $context->mfaVerified()) {
            abort_if($grants(DB::query())->exists(), 403, 'MFA login required.');
        }
        return DB::table('companies')->where('companies.tenant_id', $context->id())
            ->whereExists(fn (Builder $q) => $grants($q->selectRaw('1'))->whereColumn('g.company_id', 'companies.id')->whereColumn('g.tenant_id', 'companies.tenant_id'));
    }
}
