<?php
namespace App\Tenancy;

use App\Audit\Audit;
use App\Http\Resources\ProjectedRow;
use App\Messaging\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Company invitations (TEN-03). The email lives only in the invitation row; audit keeps ids and permission codes.
// The secret is minted by the invitation.send outbox handler, never here, and only its SHA-256 hash is stored.
final class InvitationController
{
    private const FIELDS = ['id','email','permissions','requires_mfa','status','expires_at','created_at','version'];
    public function __construct(private readonly CompanyAdministration $admin) {}
    private function query(string $company)
    {
        return DB::table('invitations')->where('tenant_id',app(TenantContext::class)->id())->where('company_id',$company);
    }
    private function present(object $row): array
    {
        $row=(array)$row; $row['permissions']=json_decode($row['permissions'],true);
        // Expiry is evaluated at read time; the stored row stays pending until replaced or cancelled.
        if($row['status']==='pending' && now()->greaterThanOrEqualTo($row['expires_at'])) {$row['status']='expired';}
        return $row;
    }
    private function expires() { return now()->addHours((int)config('invitations.ttl_hours')); }
    public function index(Request $r, string $company)
    {
        $this->admin->authorize($r,$company);
        $r->validate(['status'=>'sometimes|in:pending,all','page'=>'sometimes|integer|min:1','per_page'=>'sometimes|integer|min:1|max:100']);
        $q=$this->query($company);
        if($r->input('status','pending')==='pending') {$q->where('status','pending')->where('expires_at','>',now());}
        $page=$q->orderByDesc('created_at')->orderBy('id')->paginate((int)$r->input('per_page',25),self::FIELDS);
        return ProjectedRow::collection($page->through(fn($row)=>$this->present($row)));
    }
    public function store(Request $r, string $company)
    {
        [,$actor,$held]=$this->admin->authorize($r,$company,true);
        $data=$r->validate([
            'email'=>'required|string|email|max:254','reason'=>['required','string','max:500','regex:/\S/u'],
            'permissions'=>'required|array|min:1|max:'.count(PermissionCatalog::LABELS),
            'permissions.*'=>['required','string','distinct',Rule::in(array_keys(PermissionCatalog::LABELS))],
        ]);
        $email=Str::lower(trim($data['email'])); $permissions=$data['permissions']; sort($permissions);
        // Same delegation rule as the grant editor: only permissions the actor currently holds in this company.
        abort_if((bool)array_diff($permissions,$held),403,'You can invite only with permissions you currently hold in this company.');
        if(!in_array('company.read',$permissions,true)) {throw ValidationException::withMessages(['permissions'=>'Company access is required in every invitation.']);}
        if(Str::lower((string)DB::table('users')->where('id',app(TenantContext::class)->userId())->value('email'))===$email) {throw ValidationException::withMessages(['email'=>'You cannot invite yourself.']);}
        $member=DB::table('tenant_memberships as m')->join('users as u','u.id','=','m.user_id')->where('m.tenant_id',$actor->tenant_id)
            ->whereRaw('lower(u.email)=?',[$email])->where('m.status','!=','revoked')
            ->whereExists(fn($q)=>$q->selectRaw('1')->from('company_grants as g')->where('g.tenant_id',$actor->tenant_id)->where('g.company_id',$company)->whereColumn('g.membership_id','m.id'))->exists();
        if($member) {throw ValidationException::withMessages(['email'=>'This person already has access to this company; use Permissions to change it.']);}
        // A lapsed pending invitation no longer blocks a new one.
        $this->query($company)->where('email',$email)->where('status','pending')->where('expires_at','<=',now())->update(['status'=>'expired','token_hash'=>null,'updated_at'=>now()]);
        if($this->query($company)->where('email',$email)->where('status','pending')->exists()) {throw ValidationException::withMessages(['email'=>'A pending invitation already exists for this email. Resend or cancel it.']);}
        $id=(string)Str::uuid(); $mfa=PermissionCatalog::requiresMfa($permissions); $expires=$this->expires();
        $this->query($company)->insert(['id'=>$id,'tenant_id'=>$actor->tenant_id,'company_id'=>$company,'email'=>$email,'permissions'=>json_encode($permissions),
            'requires_mfa'=>$mfa,'expires_at'=>$expires,'invited_by'=>app(TenantContext::class)->userId()]);
        Outbox::record('invitation.send',['invitation'=>$id,'send'=>1],"invitation.send:$id:1",$company);
        Audit::record($company,'invitation.created',$id,['permissions'=>$permissions,'requires_mfa'=>$mfa],$data['reason']);
        return response()->json(['data'=>['id'=>$id,'email'=>$email,'permissions'=>$permissions,'requires_mfa'=>$mfa,'status'=>'pending','expires_at'=>$expires->toIso8601String(),'version'=>1]],201);
    }
    /** @return array{0:object,1:array} locked invitation row and validated input, after authority and version checks */
    private function pending(Request $r, string $company, string $invitation): array
    {
        abort_unless(Str::isUuid($invitation),404);
        [,,$held]=$this->admin->authorize($r,$company,true);
        $row=$this->query($company)->where('id',$invitation)->lockForUpdate()->first(); abort_unless($row,404);
        $data=$r->validate(['version'=>'required|integer|min:1','reason'=>['required','string','max:500','regex:/\S/u']]);
        abort_if((bool)array_diff(json_decode($row->permissions,true),$held),403,'This invitation includes permissions you do not currently hold in this company.');
        abort_unless($row->version===$data['version'],409,'This invitation changed. Reload and review it.');
        abort_unless($row->status==='pending' && now()->lessThan($row->expires_at),409,'Only pending, unexpired invitations can be changed.');
        return [$row,$data];
    }
    public function resend(Request $r, string $company, string $invitation): array
    {
        [$row,$data]=$this->pending($r,$company,$invitation);
        $send=$row->send+1; $expires=$this->expires();
        // Clearing the hash revokes the previous link at once; the handler mints a fresh secret for this send only.
        $this->query($company)->where('id',$invitation)->update(['send'=>$send,'version'=>$row->version+1,'expires_at'=>$expires,'token_hash'=>null,'updated_at'=>now()]);
        Outbox::record('invitation.send',['invitation'=>$invitation,'send'=>$send],"invitation.send:$invitation:$send",$company);
        Audit::record($company,'invitation.resent',$invitation,['send'=>$send],$data['reason']);
        return ['data'=>$this->present($this->query($company)->where('id',$invitation)->first(self::FIELDS))];
    }
    public function cancel(Request $r, string $company, string $invitation): array
    {
        [$row,$data]=$this->pending($r,$company,$invitation);
        $this->query($company)->where('id',$invitation)->update(['status'=>'cancelled','cancelled_at'=>now(),'token_hash'=>null,'version'=>$row->version+1,'updated_at'=>now()]);
        Audit::record($company,'invitation.cancelled',$invitation,[],$data['reason']);
        return ['data'=>$this->present($this->query($company)->where('id',$invitation)->first(self::FIELDS))];
    }
}
