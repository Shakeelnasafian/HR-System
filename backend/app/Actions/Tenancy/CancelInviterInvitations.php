<?php

namespace App\Actions\Tenancy;

use App\Models\Tenancy\Invitation;

/** An inviter who loses access.manage or an invited permission in the company loses those pending invitations too. */
final class CancelInviterInvitations
{
    public function __construct(private readonly CancelPendingInvitations $cancel) {}

    /** @param  list<string>  $remaining  the inviter's permissions in the company after the change */
    public function handle(string $company, int $inviter, array $remaining, string $reason): void
    {
        // jsonb containment: the invitation's permissions plus access.manage must all remain held.
        $invitations = Invitation::query()->where('company_id', $company)->where('invited_by', $inviter)
            ->whereRaw("NOT (CAST(? AS jsonb) @> (permissions || '[\"access.manage\"]'::jsonb))", [json_encode(array_values($remaining))]);
        $this->cancel->handle($invitations, 'invitation.cancelled', ['cause' => 'inviter_access_removed'], $reason);
    }
}
