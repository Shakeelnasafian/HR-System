<?php
namespace App\Tenancy;

use App\Http\Resources\ProjectedRow;
use App\Audit\Audit;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AccessController
{
    public function company(Request $r, string $company, bool $lock=false): object
    {
        abort_unless(Str::isUuid($company),404);
        $q=app(CompanyAccess::class)->readable('access.manage')->where('companies.id',$company);
        if($lock) {$q->lockForUpdate();}
        $row=$q->first(); abort_unless($row,404);
        // Permission administration always requires MFA, even if a membership was misconfigured.
        abort_unless($r->user()->two_factor_confirmed_at && $r->user()->two_factor_secret
            && (int)$r->session()->get('mfa_user_id')===(int)$r->user()->id,403,'Verify MFA before managing permissions.');
        return $row;
    }
    private function memberships(string $company): Builder
    {
        $tenant=app(TenantContext::class)->id();
        return DB::table('tenant_memberships as m')->join('users as u','u.id','=','m.user_id')
            ->where('m.tenant_id',$tenant)->whereExists(function(Builder $q) use($company,$tenant){
                $q->selectRaw('1')->from('company_grants as g')->where('g.tenant_id',$tenant)->where('g.company_id',$company)->whereColumn('g.membership_id','m.id');
            });
    }
    public function actorMembership(): object
    {
        $context=app(TenantContext::class);
        return DB::table('tenant_memberships')->where('tenant_id',$context->id())->where('user_id',$context->userId())->where('status','active')->firstOrFail();
    }
    public function grants(string $company, string $membership): Builder
    {
        return DB::table('company_grants')->where('tenant_id',app(TenantContext::class)->id())->where('company_id',$company)->where('membership_id',$membership);
    }
    public function index(Request $r, string $company)
    {
        $row=$this->company($r,$company);
        $r->validate(['page'=>'sometimes|integer|min:1','per_page'=>'sometimes|integer|min:1|max:100']);
        $actor=$this->actorMembership();
        $held=$this->grants($company,$actor->id)->pluck('permission')->all();
        $page=$this->memberships($company)->orderBy('u.name')->orderBy('m.id')
            ->paginate((int)$r->input('per_page',25),['m.id','m.status','m.requires_mfa','u.name','u.email']);
        $all=DB::table('company_grants')->where('tenant_id',app(TenantContext::class)->id())->where('company_id',$company)
            ->whereIn('membership_id',$page->getCollection()->pluck('id'))->orderBy('permission')->get(['membership_id','permission'])->groupBy('membership_id');
        $page->through(fn($m)=>array_merge((array)$m,['permissions'=>($all->get($m->id,collect()))->pluck('permission')->all()]));
        $catalog=[];
        foreach(PermissionCatalog::LABELS as $permission=>$label) { $catalog[]=['permission'=>$permission,'label'=>$label,'delegable'=>in_array($permission,$held,true)]; }
        return ProjectedRow::collection($page)->additional(['access_version'=>$row->access_version,'actor_membership_id'=>$actor->id,'catalog'=>$catalog]);
    }
    public function replace(Request $r, string $company, string $membership): array
    {
        abort_unless(Str::isUuid($membership),404);
        // Serialize all grant mutations in a company, then evaluate the actor's current grants again.
        $row=$this->company($r,$company,true);
        $actor=$this->actorMembership();
        abort_unless($this->grants($company,$actor->id)->where('permission','access.manage')->exists(),404);
        abort_if($actor->id===$membership,403,'You cannot change your own permissions. Ask another authorized administrator.');
        $target=$this->memberships($company)->where('m.id',$membership)->first(['m.id','m.status','m.requires_mfa']); abort_unless($target,404);
        $data=$r->validate([
            'version'=>'required|integer|min:1','reason'=>'required|string|max:500',
            'permissions'=>'present|array|max:'.count(PermissionCatalog::LABELS),
            'permissions.*'=>['required','string','distinct',Rule::in(array_keys(PermissionCatalog::LABELS))],
        ]);
        abort_unless($row->access_version===$data['version'],409,'Company permissions changed. Reload and review the latest grants.');
        abort_unless($target->status==='active',409,'This membership is inactive. An operator must review it first.');
        $current=$this->grants($company,$membership)->pluck('permission')->all();
        $held=$this->grants($company,$actor->id)->pluck('permission')->all();
        $desired=$data['permissions'];sort($desired);
        $added=array_values(array_diff($desired,$current)); $removed=array_values(array_diff($current,$desired));
        abort_if(count(array_diff(array_merge($added,$removed),$held))>0,403,'You can change only permissions you currently hold in this company.');
        if($desired && !in_array('company.read',$desired,true)) {throw ValidationException::withMessages(['permissions'=>'Company access is required when other permissions are assigned.']);}
        if(PermissionCatalog::requiresMfa($desired) && !$target->requires_mfa) {throw ValidationException::withMessages(['permissions'=>'An operator must require MFA on this membership before privileged access can be assigned.']);}
        if($added || $removed) {
            // Preserve existing grants; only the authorized difference is changed.
            $this->grants($company,$membership)->whereIn('permission',$removed)->delete();
            foreach($added as $permission) {
                DB::table('company_grants')->insert(['tenant_id'=>app(TenantContext::class)->id(),'company_id'=>$company,'membership_id'=>$membership,'permission'=>$permission]);
            }
            DB::table('companies')->where('tenant_id',app(TenantContext::class)->id())->where('id',$company)->update(['access_version'=>$row->access_version+1]);
            Audit::record($company,'membership.company_permissions.updated',$membership,['added'=>$added,'removed'=>$removed],$data['reason']);
            $row->access_version++;
        }
        return ['data'=>['membership_id'=>$membership,'permissions'=>$desired,'access_version'=>$row->access_version]];
    }
}
