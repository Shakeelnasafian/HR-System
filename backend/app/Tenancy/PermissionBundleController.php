<?php
namespace App\Tenancy;

use App\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// Immutable templates: copying a bundle never creates a live role assignment.
final class PermissionBundleController
{
    private function query(string $company)
    {
        return DB::table('permission_bundles')->where('tenant_id',app(TenantContext::class)->id())->where('company_id',$company);
    }
    private function authorize(Request $r, string $company, bool $lock=false): array
    {
        $access=app(AccessController::class);
        $access->company($r,$company,$lock);
        $held=$access->grants($company,$access->actorMembership()->id)->pluck('permission')->all();
        // Recheck after the company lock, shared with grant changes.
        abort_unless(in_array('access.manage',$held,true),404);
        return $held;
    }
    public function index(Request $r, string $company): array
    {
        $held=$this->authorize($r,$company);
        return ['data'=>$this->query($company)->where('archived',false)->orderBy('name')->get(['id','name','permissions'])->map(function($row) use($held) {
            $row->permissions=json_decode($row->permissions,true);
            $row->delegable=!array_diff($row->permissions,$held);
            return $row;
        })];
    }
    public function store(Request $r, string $company)
    {
        $held=$this->authorize($r,$company,true);
        $data=$r->validate([
            'name'=>['required','string','max:100','regex:/\S/u'],
            'reason'=>['required','string','max:500','regex:/\S/u'],
            'permissions'=>'required|array|min:1|max:'.count(PermissionCatalog::LABELS),
            'permissions.*'=>['required','string','distinct',Rule::in(array_keys(PermissionCatalog::LABELS))],
        ]);
        abort_if((bool)array_diff($data['permissions'],$held),403,'A bundle can contain only permissions you currently hold in this company.');
        abort_unless(in_array('company.read',$data['permissions'],true),422,'Include company access in the bundle.');
        abort_if($this->query($company)->where('archived',false)->count()>=100,409,'Archive an unused bundle before adding another.');
        $id=(string)Str::uuid(); sort($data['permissions']);
        $this->query($company)->insert(['id'=>$id,'tenant_id'=>app(TenantContext::class)->id(),'company_id'=>$company,
            'name'=>trim($data['name']),'permissions'=>json_encode($data['permissions']),'created_at'=>now(),'updated_at'=>now()]);
        Audit::record($company,'permission_bundle.created',$id,['permissions'=>$data['permissions']],$data['reason']);
        return response()->json(['data'=>['id'=>$id,'name'=>trim($data['name']),'permissions'=>$data['permissions']]],201);
    }
    public function archive(Request $r, string $company, string $bundle): array
    {
        abort_unless(Str::isUuid($bundle),404);
        $held=$this->authorize($r,$company,true);
        $row=$this->query($company)->where('id',$bundle)->first(); abort_unless($row,404);
        $data=$r->validate(['reason'=>['required','string','max:500','regex:/\S/u']]);
        abort_if((bool)array_diff(json_decode($row->permissions,true),$held),403,'You cannot archive a bundle outside your current authority.');
        if(!$row->archived) {
            $this->query($company)->where('id',$bundle)->update(['archived'=>true,'updated_at'=>now()]);
            Audit::record($company,'permission_bundle.archived',$bundle,[],$data['reason']);
        }
        return ['data'=>['id'=>$bundle,'archived'=>true]];
    }
}
