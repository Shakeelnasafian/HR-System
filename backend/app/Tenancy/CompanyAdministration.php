<?php
namespace App\Tenancy;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Shared gate for company permission administration (grants and bundles).
final class CompanyAdministration
{
    /** @return array{0:object,1:object,2:list<string>} company row, actor membership, actor's current company permissions */
    public function authorize(Request $r, string $company, bool $lock=false): array
    {
        abort_unless(Str::isUuid($company),404);
        $q=app(CompanyAccess::class)->readable('access.manage')->where('companies.id',$company);
        if($lock) {$q->lockForUpdate();}
        $row=$q->first(); abort_unless($row,404);
        // Permission administration always requires MFA, even if a membership was misconfigured.
        abort_unless($r->user()->two_factor_confirmed_at && $r->user()->two_factor_secret
            && (int)$r->session()->get('mfa_user_id')===(int)$r->user()->id,403,'Verify MFA before managing permissions.');
        $context=app(TenantContext::class);
        $actor=DB::table('tenant_memberships')->where('tenant_id',$context->id())->where('user_id',$context->userId())->where('status','active')->firstOrFail();
        $held=$this->grants($company,$actor->id)->pluck('permission')->all();
        // Recheck after the company lock, shared by every grant and bundle mutation.
        abort_unless(in_array('access.manage',$held,true),404);
        return [$row,$actor,$held];
    }
    public function grants(string $company, string $membership): Builder
    {
        return DB::table('company_grants')->where('tenant_id',app(TenantContext::class)->id())->where('company_id',$company)->where('membership_id',$membership);
    }
}
