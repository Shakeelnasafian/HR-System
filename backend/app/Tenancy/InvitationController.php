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
// Expiry is always decided by the database clock.
final class InvitationController
{
    public function __construct(private readonly CompanyAdministration $admin) {}
    private function query(string $company)
    {
        return DB::table('invitations')->where('tenant_id',app(TenantContext::class)->id())->where('company_id',$company);
    }
    private function fields(): array
    {
        // A stored pending row past expires_at is reported as expired.
        return ['id','email','permissions','requires_mfa',DB::raw("CASE WHEN status = 'pending' AND expires_at <= clock_timestamp() THEN 'expired' ELSE status END AS status"),
            'expires_at','created_at','version'];
    }
    private function present(object $row): array { $row=(array)$row; $row['permissions']=json_decode($row['permissions'],true); return $row; }
    private function expires() { return DB::raw('clock_timestamp() + make_interval(hours => '.(int)config('invitations.ttl_hours').')'); }
    private function show(string $company, string $id): array { return $this->present($this->query($company)->where('id',$id)->first($this->fields())); }
    public function index(Request $r, string $company)
    {
        $this->admin->authorize($r,$company);
        $r->validate(['status'=>'sometimes|in:pending,all','page'=>'sometimes|integer|min:1','per_page'=>'sometimes|integer|min:1|max:100']);
        $q=$this->query($company);
        if($r->input('status','pending')==='pending') {$q->where('status','pending')->whereRaw('expires_at > clock_timestamp()');}
        $page=$q->orderByDesc('created_at')->orderBy('id')->paginate((int)$r->input('per_page',25),$this->fields());
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
        $this->query($company)->where('email',$email)->where('status','pending')->whereRaw('expires_at <= clock_timestamp()')
            ->update(['status'=>'expired','token_hash'=>null,'updated_at'=>DB::raw('clock_timestamp()')]);
        if($this->query($company)->where('email',$email)->where('status','pending')->exists()) {throw ValidationException::withMessages(['email'=>'A pending invitation already exists for this email. Resend or cancel it.']);}
        $id=(string)Str::uuid(); $mfa=PermissionCatalog::requiresMfa($permissions);
        $this->query($company)->insert(['id'=>$id,'tenant_id'=>$actor->tenant_id,'company_id'=>$company,'email'=>$email,'permissions'=>json_encode($permissions),
            'requires_mfa'=>$mfa,'expires_at'=>$this->expires(),'invited_by'=>app(TenantContext::class)->userId()]);
        Outbox::record('invitation.send',['invitation'=>$id,'send'=>1],"invitation.send:$id:1",$company);
        Audit::record($company,'invitation.created',$id,['permissions'=>$permissions,'requires_mfa'=>$mfa],$data['reason']);
        return response()->json(['data'=>$this->show($company,$id)],201);
    }
    /** @return array{0:object,1:array} locked invitation row and validated input, after authority and version checks */
    private function pending(Request $r, string $company, string $invitation): array
    {
        abort_unless(Str::isUuid($invitation),404);
        [,,$held]=$this->admin->authorize($r,$company,true);
        $row=$this->query($company)->where('id',$invitation)->lockForUpdate()
            ->first(['id','permissions','status','send','version',DB::raw('expires_at > clock_timestamp() AS live')]); abort_unless($row,404);
        $data=$r->validate(['version'=>'required|integer|min:1','reason'=>['required','string','max:500','regex:/\S/u']]);
        abort_if((bool)array_diff(json_decode($row->permissions,true),$held),403,'This invitation includes permissions you do not currently hold in this company.');
        abort_unless($row->version===$data['version'],409,'This invitation changed. Reload and review it.');
        abort_unless($row->status==='pending' && $row->live,409,'Only pending, unexpired invitations can be changed.');
        return [$row,$data];
    }
    public function resend(Request $r, string $company, string $invitation): array
    {
        [$row,$data]=$this->pending($r,$company,$invitation);
        $send=$row->send+1;
        // Clearing the hash revokes the previous link at once; the handler mints a fresh secret for this send only.
        $this->query($company)->where('id',$invitation)->update(['send'=>$send,'version'=>$row->version+1,'expires_at'=>$this->expires(),'token_hash'=>null,'updated_at'=>DB::raw('clock_timestamp()')]);
        Outbox::record('invitation.send',['invitation'=>$invitation,'send'=>$send],"invitation.send:$invitation:$send",$company);
        Audit::record($company,'invitation.resent',$invitation,['send'=>$send],$data['reason']);
        return ['data'=>$this->show($company,$invitation)];
    }
    public function cancel(Request $r, string $company, string $invitation): array
    {
        [,$data]=$this->pending($r,$company,$invitation);
        $this->admin->cancelInvitations($this->query($company)->where('id',$invitation),'invitation.cancelled',[],$data['reason']);
        return ['data'=>$this->show($company,$invitation)];
    }
}
