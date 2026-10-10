<?php

namespace App\Actions\Tenancy;

use App\Models\Tenancy\Invitation;
use App\Services\Audit\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Cancels the pending invitations selected by a query (the caller already locked the company), auditing each. */
final class CancelPendingInvitations
{
    /** @param  Builder<Invitation>  $invitations */
    public function handle(Builder $invitations, string $action, array $changes, string $reason): void
    {
        $rows = (clone $invitations)->where('status', 'pending')->lockForUpdate()->get(['id', 'company_id']);
        if ($rows->isEmpty()) {
            return;
        }
        // Database clock for the cancellation time, as for every invitation timestamp.
        Invitation::query()->whereIn('id', $rows->pluck('id'))->update(['status' => 'cancelled',
            'cancelled_at' => DB::raw('clock_timestamp()'), 'token_hash' => null, 'version' => DB::raw('version + 1'), 'updated_at' => DB::raw('clock_timestamp()')]);
        foreach ($rows as $row) {
            Audit::record($row->company_id, $action, $row->id, $changes, $reason);
        }
    }
}
