<?php
namespace App\Tenancy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CompanyAccess
{
    public function readable(): Builder
    {
        $context = app(TenantContext::class);
        return DB::table('companies')->where('companies.tenant_id', $context->id())
            ->whereExists(function (Builder $q) use ($context) {
                $q->selectRaw('1')->from('company_grants as g')
                    ->join('tenant_memberships as m', 'm.id', '=', 'g.membership_id')
                    ->whereColumn('g.company_id', 'companies.id')->whereColumn('g.tenant_id', 'companies.tenant_id')
                    ->where('m.user_id', $context->userId())->where('m.status', 'active')->where('g.permission', 'company.read');
            });
    }
}
