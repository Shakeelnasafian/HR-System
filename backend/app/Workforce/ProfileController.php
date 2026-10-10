<?php
namespace App\Workforce;

use App\Audit\Audit;
use App\Tenancy\CompanyAccess;
use App\Tenancy\ScopesCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Private profile (EMP-01, SEC-03/04). Separately authorized (profile.read/profile.write, both privileged), limited to the
 * company's enabled field set, and reachable only while the person has a draft or active employment in the route company.
 * Every read is audited with field names only; values never enter audit or directory payloads.
 */
final class ProfileController
{
    use ScopesCompany;
    public const FIELDS = ['birth_date','nationality','personal_email','personal_phone','address','emergency_contacts'];
    private const RULES = [
        'birth_date'=>'nullable|date_format:Y-m-d', 'nationality'=>'nullable|string|max:100',
        'personal_email'=>'nullable|string|email|max:254', 'personal_phone'=>'nullable|string|max:50', 'address'=>'nullable|string|max:1000',
        'emergency_contacts'=>'nullable|array|list|max:5', 'emergency_contacts.*'=>'required|array:name,relationship,phone',
        'emergency_contacts.*.name'=>'required|string|max:120', 'emergency_contacts.*.relationship'=>'required|string|max:60', 'emergency_contacts.*.phone'=>'required|string|max:50',
    ];

    private function settings(object $company): array
    {
        return ['enabled'=>json_decode($company->profile_fields,true),'available'=>self::FIELDS,'version'=>$company->profile_fields_version];
    }
    public function fields(string $company): array
    {
        return ['data'=>$this->settings($this->company($company,'workforce.read'))];
    }
    public function configure(Request $r, string $company): array
    {
        $row=$this->company($company,'company.manage',true);
        $data=$r->validate(['version'=>'required|integer|min:1','reason'=>'required|string|max:500','enabled'=>'present|array','enabled.*'=>['required','string','distinct',Rule::in(self::FIELDS)]]);
        abort_unless($row->profile_fields_version===$data['version'],409,'Profile field settings changed. Reload before saving.');
        $before=json_decode($row->profile_fields,true); $after=array_values(array_intersect(self::FIELDS,$data['enabled']));
        if($before!==$after) {
            DB::table('companies')->where('tenant_id',$this->tenant())->where('id',$company)->update(['profile_fields'=>json_encode($after),'profile_fields_version'=>$row->profile_fields_version+1,'updated_at'=>now()]);
            Audit::record($company,'profile_fields.updated',$company,['enabled'=>array_values(array_diff($after,$before)),'disabled'=>array_values(array_diff($before,$after))],$data['reason']);
        }
        return ['data'=>$this->settings(DB::table('companies')->where('tenant_id',$this->tenant())->where('id',$company)->first())];
    }
    /** Ended/cancelled-only relationships are 404: former-employee profile access awaits an HR/legal policy decision. */
    private function subject(string $company, string $employee): void
    {
        abort_unless(Str::isUuid($employee)&&$this->rows('employments',$company)->where('employee_id',$employee)->whereIn('status',['draft','active'])->exists(),404);
    }
    private function enabled(string $company): array
    {
        // FOR SHARE: a concurrent field-configuration change waits for this request instead of racing it.
        return json_decode(DB::table('companies')->where('tenant_id',$this->tenant())->where('id',$company)->sharedLock()->value('profile_fields'),true);
    }
    private function profile(string $employee, bool $lock=false): ?object
    {
        $q=DB::table('employee_profiles')->where('tenant_id',$this->tenant())->where('employee_id',$employee);
        return ($lock?$q->lockForUpdate():$q)->first();
    }
    private static function contacts(array $list): array { return array_map(fn($c)=>['name'=>$c['name'],'relationship'=>$c['relationship'],'phone'=>$c['phone']],$list); }
    private static function value(?object $row, string $field): mixed
    {
        $value=$row?->$field; return $field==='emergency_contacts'&&$value!==null?self::contacts(json_decode($value,true)):$value;
    }
    public function show(string $company, string $employee): array
    {
        $this->company($company,'profile.read'); $this->subject($company,$employee);
        $enabled=$this->enabled($company); $row=$this->profile($employee); $fields=[];
        foreach($enabled as $field) { $fields[$field]=self::value($row,$field); }
        Audit::record($company,'profile.viewed',$employee,['fields'=>$enabled]);
        return ['data'=>['employee_id'=>$employee,'version'=>$row->version??0,'fields'=>(object)$fields]];
    }
    /**
     * version 0 means no profile row exists yet. Nulls clear a value. The response never contains values (profile.write does not imply
     * profile.read). Every accepted PATCH, including a no-op, bumps the version and is audited with submitted and changed keys; only
     * actors who may also read the profile learn which keys actually changed, so a write-only actor cannot confirm guessed values.
     */
    public function update(Request $r, string $company, string $employee): array
    {
        $companyRow=$this->company($company,'profile.write'); $this->subject($company,$employee);
        $r->validate(['version'=>'required|integer|min:0','reason'=>'required|string|max:500','fields'=>'required|array|min:1']);
        $enabled=$this->enabled($company); $input=$r->input('fields');
        $foreign=array_diff(array_keys($input),$enabled);
        if($foreign) { throw ValidationException::withMessages(array_fill_keys(array_map(fn($k)=>"fields.$k",$foreign),'This field is not collected by this company.')); }
        $rules=array_filter(['birth_date'=>'nullable|date_format:Y-m-d|before_or_equal:'.now($companyRow->timezone)->toDateString()]+self::RULES,fn($k)=>in_array(explode('.',$k)[0],$enabled,true),ARRAY_FILTER_USE_KEY);
        $values=array_intersect_key($r->validate(array_combine(array_map(fn($k)=>"fields.$k",array_keys($rules)),$rules))['fields']??[],$input);
        if(isset($values['emergency_contacts'])) { $values['emergency_contacts']=self::contacts($values['emergency_contacts']); }
        // The employee row is the per-person aggregate lock (shared with employment transitions); it also serializes first creation.
        DB::table('employees')->where('tenant_id',$this->tenant())->where('id',$employee)->lockForUpdate()->first();
        $row=$this->profile($employee,true); $version=$row->version??0;
        abort_unless($version===(int)$r->input('version'),409,'This profile changed. Reload before saving.');
        $submitted=array_keys($values);
        $changed=array_keys(array_filter($values,fn($v,$k)=>self::value($row,$k)!==$v,ARRAY_FILTER_USE_BOTH));
        $store=array_map(fn($v)=>is_array($v)?json_encode($v):$v,array_intersect_key($values,array_flip($changed)));
        if($row) { DB::table('employee_profiles')->where('tenant_id',$this->tenant())->where('employee_id',$employee)->update($store+['version'=>++$version,'updated_at'=>now()]); }
        else { DB::table('employee_profiles')->insert($store+['tenant_id'=>$this->tenant(),'employee_id'=>$employee,'version'=>++$version,'created_at'=>now(),'updated_at'=>now()]); }
        Audit::record($company,'profile.updated',$employee,['fields'=>$changed,'submitted'=>$submitted],$r->input('reason'));
        $reader=app(CompanyAccess::class)->readable('profile.read')->where('companies.id',$company)->exists();
        return ['data'=>['employee_id'=>$employee,'version'=>$version,'updated'=>$reader?$changed:$submitted]];
    }
}
