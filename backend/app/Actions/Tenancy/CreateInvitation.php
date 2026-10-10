<?php

namespace App\Actions\Tenancy;

use App\Models\Tenancy\Company;
use App\Models\Tenancy\Invitation;
use App\Models\Tenancy\TenantMembership;
use App\Models\User;
use App\Services\Audit\Audit;
use App\Services\Messaging\Outbox;
use App\Services\Tenancy\PermissionCatalog;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Company invitations (TEN-03). The email lives only in the invitation row; audit keeps ids and permission codes.
 * The secret is minted by the invitation.send outbox handler, never here, and only its SHA-256 hash is stored.
 * Expiry is always decided by the database clock.
 */
final class CreateInvitation
{
    public function __construct(private readonly ManagerAuthority $authority, private readonly TenantContext $context) {}

    /** @param  Company  $company  the route company, locked FOR UPDATE */
    public function handle(Company $company, array $data): string
    {
        [, $held] = $this->authority->of($company);
        $email = Str::lower(trim($data['email']));
        $permissions = $data['permissions'];
        sort($permissions);
        // Same delegation rule as the grant editor: only permissions the actor currently holds in this company.
        abort_if((bool) array_diff($permissions, $held), 403, 'You can invite only with permissions you currently hold in this company.');
        if (! in_array('company.read', $permissions, true)) {
            throw ValidationException::withMessages(['permissions' => 'Company access is required in every invitation.']);
        }
        if (Str::lower((string) User::query()->whereKey($this->context->userId())->value('email')) === $email) {
            throw ValidationException::withMessages(['email' => 'You cannot invite yourself.']);
        }
        $member = TenantMembership::query()->join('users as u', 'u.id', '=', 'tenant_memberships.user_id')
            ->whereRaw('lower(u.email)=?', [$email])->where('tenant_memberships.status', '!=', 'revoked')->withAccessTo($company->id)->exists();
        if ($member) {
            throw ValidationException::withMessages(['email' => 'This person already has access to this company; use Permissions to change it.']);
        }
        $invitations = fn () => Invitation::query()->where('company_id', $company->id);
        // A lapsed pending invitation no longer blocks a new one.
        $invitations()->where('email', $email)->where('status', 'pending')->whereRaw('expires_at <= clock_timestamp()')
            ->update(['status' => 'expired', 'token_hash' => null, 'updated_at' => DB::raw('clock_timestamp()')]);
        if ($invitations()->where('email', $email)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['email' => 'A pending invitation already exists for this email. Resend or cancel it.']);
        }
        $id = (string) Str::uuid();
        $mfa = PermissionCatalog::requiresMfa($permissions);
        // Plain insert: created_at/updated_at come from the database clock defaults, expires_at from the database clock.
        Invitation::query()->insert(['id' => $id, 'tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'email' => $email, 'permissions' => json_encode($permissions),
            'requires_mfa' => $mfa, 'expires_at' => Invitation::expiry(), 'invited_by' => $this->context->userId()]);
        Outbox::record('invitation.send', ['invitation' => $id, 'send' => 1], "invitation.send:$id:1", $company->id);
        Audit::record($company->id, 'invitation.created', $id, ['permissions' => $permissions, 'requires_mfa' => $mfa], $data['reason']);

        return $id;
    }
}
