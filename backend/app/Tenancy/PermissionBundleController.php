<?php
namespace App\Tenancy;

use App\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Immutable templates: copying a bundle never creates a live role assignment.
final class PermissionBundleController
{
    public function __construct(private readonly CompanyAdministration $admin) {}
    private function query(string $company)
    {
        return DB::table('permission_bundles')->where('tenant_id',app(TenantContext::class)->id())->where('company_id',$company);
    }
    public function index(Request $r, string $company): array
    {
        [,,$held]=$this->admin->authorize($r,$company);
        return ['data'=>$this->query($company)->where('archived',false)->orderBy('name')->get(['id','name','permissions'])->map(function($row) use($held) {
            $row->permissions=json_decode($row->permissions,true);
            $row->delegable=!array_diff($row->permissions,$held);
            return $row;
        })];
    }
    public function store(Request $r, string $company)
    {
        [,,$held]=$this->admin->authorize($r,$company,true);
        $data=$r->validate([
            'name'=>['required','string','max:100','regex:/\S/u'],
            'reason'=>['required','string','max:500','regex:/\S/u'],
            'permissions'=>'required|array|min:1|max:'.count(PermissionCatalog::LABELS),
            'permissions.*'=>['required','string','distinct',Rule::in(array_keys(PermissionCatalog::LABELS))],
        ]);
        abort_if((bool)array_diff($data['permissions'],$held),403,'A bundle can contain only permissions you currently hold in this company.');
        if(!in_array('company.read',$data['permissions'],true)) {throw ValidationException::withMessages(['permissions'=>'Company access is required in every bundle.']);}
        abort_if($this->query($company)->where('archived',false)->count()>=100,409,'Archive an unused bundle before adding another.');
        $name=trim($data['name']); $taken=ValidationException::withMessages(['name'=>'This name is already used by a current or archived bundle. Choose a new name.']);
        // Names stay reserved after archive; the unique index on lower(name) also covers concurrent inserts.
        if($this->query($company)->whereRaw('lower(name)=lower(?)',[$name])->exists()) {throw $taken;}
        $id=(string)Str::uuid(); sort($data['permissions']);
        try {
            DB::transaction(fn()=>$this->query($company)->insert(['id'=>$id,'tenant_id'=>app(TenantContext::class)->id(),'company_id'=>$company,
                'name'=>$name,'permissions'=>json_encode($data['permissions']),'created_at'=>now(),'updated_at'=>now()]));
        } catch(UniqueConstraintViolationException) {throw $taken;}
        Audit::record($company,'permission_bundle.created',$id,['permissions'=>$data['permissions']],$data['reason']);
        return response()->json(['data'=>['id'=>$id,'name'=>$name,'permissions'=>$data['permissions']]],201);
    }
    public function archive(Request $r, string $company, string $bundle): array
    {
        abort_unless(Str::isUuid($bundle),404);
        [,,$held]=$this->admin->authorize($r,$company,true);
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
