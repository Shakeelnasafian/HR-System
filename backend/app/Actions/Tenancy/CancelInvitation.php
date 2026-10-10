<?php

namespace App\Actions\Tenancy;

use App\Models\Tenancy\Company;
use App\Models\Tenancy\Invitation;

/** Cancels one pending invitation; its link stops working at once. */
final class CancelInvitation
{
    public function __construct(private readonly LockPendingInvitation $pending, private readonly CancelPendingInvitations $cancel) {}

    /** @param  Company  $company  the route company, locked FOR UPDATE */
    public function handle(Company $company, string $invitation, array $data): void
    {
        $this->pending->handle($company, $invitation, $data);
        $this->cancel->handle(Invitation::query()->where('company_id', $company->id)->whereKey($invitation), 'invitation.cancelled', [], $data['reason']);
    }
}
