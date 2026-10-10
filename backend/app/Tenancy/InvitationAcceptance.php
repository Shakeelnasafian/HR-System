<?php
namespace App\Tenancy;

use App\Actions\ResetUserPassword;
use App\Audit\RequestId;
use App\Audit\SecurityEvents;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Unauthenticated invitation endpoints (SPA session + CSRF, rate limited by IP). Every unusable invitation, wrong token
// or malformed selector yields the same 404; validity is decided only by the SECURITY DEFINER functions.
final class InvitationAcceptance
{
    private const GONE = 'This invitation is invalid or has expired.';

    /** @return array{0:string,1:string,2:string} tenant, invitation, SHA-256 hex of the secret */
    private function key(Request $r): array
    {
        // Only the SPA origin gets a session and CSRF verification; refuse anything else instead of skipping CSRF.
        abort_unless(EnsureFrontendRequestsAreStateful::fromFrontend($r),403,'Open the invitation link in the application.');
        [$t,$i,$k]=[$r->input('tenant'),$r->input('invitation'),$r->input('token')];
        abort_unless(is_string($t) && Str::isUuid($t) && is_string($i) && Str::isUuid($i) && is_string($k) && preg_match('/^[A-Za-z0-9_-]{43}$/',$k),404,self::GONE);
        return [Str::lower($t),Str::lower($i),hash('sha256',$k)];
    }
    private function find(array $key): object
    {
        $row=DB::selectOne('SELECT * FROM hr_preview_invitation(?::uuid, ?::uuid, ?)',$key); abort_unless($row,404,self::GONE);
        return $row;
    }
    public function preview(Request $r)
    {
        $row=$this->find($this->key($r));
        return response()->json(['data'=>['tenant_name'=>$row->tenant_name,'company_name'=>$row->company_name,'email_hint'=>$row->email_hint,
            'expires_at'=>\Illuminate\Support\Carbon::parse($row->expires_at)->toIso8601String(),'existing_account'=>(bool)$row->existing_account]])->header('Cache-Control','no-store, private');
    }
    public function accept(Request $r)
    {
        $key=$this->key($r);
        $result=DB::transaction(function() use($r,$key) {
            $row=$this->find($key);
            if($row->existing_account) {
                // Email match is enforced again (case-insensitively) inside hr_accept_invitation.
                abort_unless($r->user(),401,'Sign in as the invited account to accept.');
                $user=(int)$r->user()->getAuthIdentifier();
            } else {
                $data=$r->validate(['name'=>['required','string','max:255','regex:/\S/u'],'password'=>ResetUserPassword::rules()]);
                // The new account is not signed in: the person signs in normally (then enrolls MFA when required).
                try {
                    $user=DB::table('users')->insertGetId(['name'=>trim($data['name']),'email'=>$row->email,'password'=>Hash::make($data['password']),'created_at'=>now(),'updated_at'=>now()]);
                } catch(UniqueConstraintViolationException) {
                    abort(404,self::GONE); // a concurrent accept created the account (and used the invitation) first
                }
            }
            try {
                $out=DB::selectOne('SELECT * FROM hr_accept_invitation(?::uuid, ?::uuid, ?, ?, ?::uuid)',[...$key,$user,app(RequestId::class)->current()]);
            } catch(QueryException $e) {
                match($e->errorInfo[0]??'') {
                    'P0002'=>abort(404,self::GONE),
                    '42501'=>abort(403,'This invitation was sent to a different account. Sign in as the invited account.'),
                    '55000'=>abort(409,'Your membership in this organization is suspended. Contact an administrator.'),
                    default=>throw $e,
                };
            }
            SecurityEvents::record('invitation.accepted',$user);
            return $out;
        });
        return response()->json(['data'=>['tenant_id'=>$key[0],'company_id'=>$result->company_id,'requires_mfa'=>(bool)$result->requires_mfa]])->header('Cache-Control','no-store, private');
    }
}
