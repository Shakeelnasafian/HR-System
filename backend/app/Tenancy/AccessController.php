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
    public function __construct(private readonly CompanyAdministration $admin) {}
    private function memberships(string $company): Builder
    {
        $tenant=app(TenantContext::class)->id();
        return DB::table('tenant_memberships as m')->join('users as u','u.id','=','m.user_id')
            ->where('m.tenant_id',$tenant)->whereExists(function(Builder $q) use($company,$tenant){
                $q->selectRaw('1')->from('company_grants as g')->where('g.tenant_id',$tenant)->where('g.company_id',$company)->whereColumn('g.membership_id','m.id');
            });
    }
    public function index(Request $r, string $company)
    {
        [$row,$actor,$held]=$this->admin->authorize($r,$company);
        $r->validate(['page'=>'sometimes|integer|min:1','per_page'=>'sometimes|integer|min:1|max:100']);
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
        [$row,$actor,$held]=$this->admin->authorize($r,$company,true);
        abort_if($actor->id===$membership,403,'You cannot change your own permissions. Ask another authorized administrator.');
        $target=$this->memberships($company)->where('m.id',$membership)->first(['m.id','m.status','m.requires_mfa']); abort_unless($target,404);
        $data=$r->validate([
            'version'=>'required|integer|min:1','reason'=>'required|string|max:500',
            'permissions'=>'present|array|max:'.count(PermissionCatalog::LABELS),
            'permissions.*'=>['required','string','distinct',Rule::in(array_keys(PermissionCatalog::LABELS))],
        ]);
        abort_unless($row->access_version===$data['version'],409,'Company permissions changed. Reload and review the latest grants.');
        abort_unless($target->status==='active',409,'This membership is inactive. An operator must review it first.');
        $current=$this->admin->grants($company,$membership)->pluck('permission')->all();
        $desired=$data['permissions'];sort($desired);
        $added=array_values(array_diff($desired,$current)); $removed=array_values(array_diff($current,$desired));
        abort_if(count(array_diff(array_merge($added,$removed),$held))>0,403,'You can change only permissions you currently hold in this company.');
        if($desired && !in_array('company.read',$desired,true)) {throw ValidationException::withMessages(['permissions'=>'Company access is required when other permissions are assigned.']);}
        if(PermissionCatalog::requiresMfa($desired) && !$target->requires_mfa) {throw ValidationException::withMessages(['permissions'=>'An operator must require MFA on this membership before privileged access can be assigned.']);}
        if($added || $removed) {
            // Preserve existing grants; only the authorized difference is changed.
            $this->admin->grants($company,$membership)->whereIn('permission',$removed)->delete();
            foreach($added as $permission) {
                DB::table('company_grants')->insert(['tenant_id'=>app(TenantContext::class)->id(),'company_id'=>$company,'membership_id'=>$membership,'permission'=>$permission]);
            }
            DB::table('companies')->where('tenant_id',app(TenantContext::class)->id())->where('id',$company)->update(['access_version'=>$row->access_version+1]);
            Audit::record($company,'membership.company_permissions.updated',$membership,['added'=>$added,'removed'=>$removed],$data['reason']);
            $row->access_version++;
        }
        return ['data'=>['membership_id'=>$membership,'permissions'=>$desired,'access_version'=>$row->access_version]];
    }
    /** Removes the whole tenant membership, only if the actor administers every company where the target holds grants. */
    public function revokeMembership(Request $r, string $company, string $membership): array
    {
        abort_unless(Str::isUuid($membership),404);
        [$row,$actor]=$this->admin->authorize($r,$company,true);
        abort_if($actor->id===$membership,403,'You cannot remove yourself from the organization.');
        $target=$this->memberships($company)->where('m.id',$membership)->where('m.status','!=','revoked')->first(['m.id']); abort_unless($target,404);
        $data=$r->validate(['version'=>'required|integer|min:1','reason'=>['required','string','max:500','regex:/\S/u']]);
        abort_unless($row->access_version===$data['version'],409,'Company permissions changed. Reload and review the latest grants.');
        $tenant=app(TenantContext::class)->id();
        $companies=DB::table('company_grants')->where('tenant_id',$tenant)->where('membership_id',$membership)->distinct()->orderBy('company_id')->pluck('company_id')->all();
        $managed=DB::table('company_grants')->where('tenant_id',$tenant)->where('membership_id',$actor->id)->where('permission','access.manage')->pluck('company_id')->all();
        abort_if((bool)array_diff($companies,$managed),403,'Target has access in companies you do not administer; remove company access instead.');
        // The definer function locks the affected companies, re-validates actor authority and the grant set, then revokes.
        try {
            $removed=DB::select('SELECT company_id, permissions FROM hr_revoke_membership(?, ?, ?::uuid[])',[$actor->id,$membership,'{'.implode(',',$companies).'}']);
        } catch(\Illuminate\Database\QueryException $e) {
            abort_if(($e->errorInfo[0]??'')==='55000',409,'Company access for this member changed. Reload and review it.');
            throw $e;
        }
        foreach($removed as $item) {
            DB::table('companies')->where('tenant_id',$tenant)->where('id',$item->company_id)->increment('access_version');
            $permissions=str_getcsv(trim($item->permissions,'{}'));
            Audit::record($item->company_id,'membership.revoked',$membership,['removed'=>$permissions],$data['reason']);
        }
        $version=DB::table('companies')->where('tenant_id',$tenant)->where('id',$company)->value('access_version');
        return ['data'=>['membership_id'=>$membership,'status'=>'revoked','access_version'=>$version]];
    }
}
