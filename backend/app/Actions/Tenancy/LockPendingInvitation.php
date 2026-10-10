<?php

namespace App\Actions\Tenancy;

use App\Models\Tenancy\Company;
use App\Models\Tenancy\Invitation;
use Illuminate\Support\Facades\DB;

/** The invitation row locked FOR UPDATE for resend/cancel, after the authority, delegation and version checks. */
final class LockPendingInvitation
{
    public function __construct(private readonly ManagerAuthority $authority) {}

    /** @param  Company  $company  the route company, locked FOR UPDATE */
    public function handle(Company $company, string $invitation, array $data): Invitation
    {
        [, $held] = $this->authority->of($company);
        // Liveness is decided by the database clock.
        $row = Invitation::query()->where('company_id', $company->id)->whereKey($invitation)->lockForUpdate()
            ->first(['id', 'permissions', 'status', 'send', 'version', DB::raw('expires_at > clock_timestamp() AS live')]);
        abort_unless($row, 404);
        abort_if((bool) array_diff($row->permissions, $held), 403, 'This invitation includes permissions you do not currently hold in this company.');
        abort_unless($row->version === $data['version'], 409, 'This invitation changed. Reload and review it.');
        abort_unless($row->status === 'pending' && $row->live, 409, 'Only pending, unexpired invitations can be changed.');

        return $row;
    }
}
