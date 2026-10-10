<?php

namespace App\Actions\Tenancy;

use App\Models\Tenancy\Company;
use App\Models\Tenancy\Invitation;
use App\Services\Audit\Audit;
use App\Services\Messaging\Outbox;
use Illuminate\Support\Facades\DB;

/** Sends a pending invitation again with a fresh secret and expiry. */
final class ResendInvitation
{
    public function __construct(private readonly LockPendingInvitation $pending) {}

    /** @param  Company  $company  the route company, locked FOR UPDATE */
    public function handle(Company $company, string $invitation, array $data): void
    {
        $row = $this->pending->handle($company, $invitation, $data);
        $send = $row->send + 1;
        // Clearing the hash revokes the previous link at once; the handler mints a fresh secret for this send only.
        Invitation::query()->where('company_id', $company->id)->whereKey($invitation)->update(['send' => $send, 'version' => $row->version + 1, 'expires_at' => Invitation::expiry(), 'token_hash' => null, 'updated_at' => DB::raw('clock_timestamp()')]);
        Outbox::record('invitation.send', ['invitation' => $invitation, 'send' => $send], "invitation.send:$invitation:$send", $company->id);
        Audit::record($company->id, 'invitation.resent', $invitation, ['send' => $send], $data['reason']);
    }
}
