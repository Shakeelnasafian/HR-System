<?php

namespace App\Actions\Tenancy;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Services\Audit\RequestId;
use App\Services\Audit\SecurityEvents;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Accepts an invitation link, creating the account first when none exists. Runs outside the tenant middleware, so it
 * owns its transaction. Name and password are validated only after the link and the signed-in account were checked.
 */
final class AcceptInvitation
{
    public function __construct(private readonly FindInvitationLink $find, private readonly RequestId $requestId) {}

    /**
     * @param  array{0:string,1:string,2:string}  $key  tenant, invitation, SHA-256 hex of the secret
     * @return object the hr_accept_invitation row (company_id, requires_mfa)
     */
    public function handle(array $key, ?Authenticatable $signedIn, array $input): object
    {
        return DB::transaction(function () use ($key, $signedIn, $input) {
            $row = $this->find->handle($key);
            if ($row->existing_account) {
                // Email match is enforced again (case-insensitively) inside hr_accept_invitation.
                abort_unless($signedIn, 401, 'Sign in as the invited account to accept.');
                $user = (int) $signedIn->getAuthIdentifier();
            } else {
                $data = Validator::make($input, ['name' => ['required', 'string', 'max:255', 'regex:/\S/u'], 'password' => ResetUserPassword::rules()])->validate();
                // The new account is not signed in: the person signs in normally (then enrolls MFA when required).
                try {
                    $user = User::query()->insertGetId(['name' => trim($data['name']), 'email' => $row->email, 'password' => Hash::make($data['password']), 'created_at' => now(), 'updated_at' => now()]);
                } catch (UniqueConstraintViolationException) {
                    abort(404, FindInvitationLink::GONE); // a concurrent accept created the account (and used the invitation) first
                }
            }
            try {
                $out = DB::selectOne('SELECT * FROM hr_accept_invitation(?::uuid, ?::uuid, ?, ?, ?::uuid)', [...$key, $user, $this->requestId->current()]);
            } catch (QueryException $e) {
                match ($e->errorInfo[0] ?? '') {
                    'P0002' => abort(404, FindInvitationLink::GONE),
                    '42501' => abort(403, 'This invitation was sent to a different account. Sign in as the invited account.'),
                    '55000' => abort(409, 'Your membership in this organization is suspended. Contact an administrator.'),
                    default => throw $e,
                };
            }
            SecurityEvents::record('invitation.accepted', $user);

            return $out;
        });
    }
}
