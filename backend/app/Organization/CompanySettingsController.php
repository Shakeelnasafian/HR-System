<?php
namespace App\Organization;

use App\Audit\Audit;
use App\Tenancy\ScopesCompany;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CompanySettingsController
{
    use ScopesCompany;
    private const FIELDS = ['id','name','code','timezone','version'];

    public function show(string $company): array
    {
        $row=$this->company($company,'company.read');
        return ['data'=>collect((array)$row)->only(self::FIELDS)];
    }
    /** The company timezone defines "today" for employment activation and calendar patterns. Code is immutable. */
    public function update(Request $r, string $company): array
    {
        $row=$this->company($company,'company.manage',true);
        $data=$r->validate(['version'=>'required|integer|min:1','reason'=>'required|string|max:500',
            'name'=>'sometimes|required|string|max:120','timezone'=>['sometimes','required','string',Rule::in(DateTimeZone::listIdentifiers())]]);
        if(!array_key_exists('name',$data)&&!array_key_exists('timezone',$data)) { throw ValidationException::withMessages(['name'=>'Change the name or the timezone.']); }
        if(isset($data['name'])) { $data['name']=trim($data['name']); if($data['name']==='') { throw ValidationException::withMessages(['name'=>'The name is required.']); } }
        abort_unless((int)$row->version===(int)$data['version'],409,'This company changed. Reload before saving.');
        $changes=array_filter(array_intersect_key($data,['name'=>1,'timezone'=>1]),fn($value,$key)=>$row->$key!==$value,ARRAY_FILTER_USE_BOTH);
        if($changes) {
            DB::table('companies')->where('tenant_id',$this->tenant())->where('id',$company)->update($changes+['version'=>$row->version+1,'updated_at'=>now()]);
            Audit::record($company,'company.updated',$company,['fields'=>array_keys($changes)],$data['reason']);
        }
        return ['data'=>DB::table('companies')->where('tenant_id',$this->tenant())->where('id',$company)->first(self::FIELDS)];
    }
}
