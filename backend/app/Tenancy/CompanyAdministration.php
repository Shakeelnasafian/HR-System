<?php

namespace App\Tenancy;

use App\Audit\Audit;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Shared gate for company permission administration (grants and bundles).
final class CompanyAdministration
{
    /** @return array{0:object,1:object,2:list<string>} company row, actor membership, actor's current company permissions */
    public function authorize(Request $r, string $company, bool $lock = false): array
    {
        abort_unless(Str::isUuid($company), 404);
        $q = app(CompanyAccess::class)->readable('access.manage')->where('companies.id', $company);
        if ($lock) {
            $q->lockForUpdate();
        }
        $row = $q->first();
        abort_unless($row, 404);
        // access.manage is privileged, so readable() above already required an MFA-verified session.
        $context = app(TenantContext::class);
        $actor = DB::table('tenant_memberships')->where('tenant_id', $context->id())->where('user_id', $context->userId())->where('status', 'active')->firstOrFail();
        $held = $this->grants($company, $actor->id)->pluck('permission')->all();
        // Recheck after the company lock, shared by every grant and bundle mutation.
        abort_unless(in_array('access.manage', $held, true), 404);

        return [$row, $actor, $held];
    }

    /** Cancels the pending invitations selected by $q (company already locked by the caller), auditing each. */
    public function cancelInvitations(Builder $q, string $action, array $changes, string $reason): void
    {
        $rows = (clone $q)->where('status', 'pending')->lockForUpdate()->get(['id', 'company_id']);
        if ($rows->isEmpty()) {
            return;
        }
        DB::table('invitations')->where('tenant_id', app(TenantContext::class)->id())->whereIn('id', $rows->pluck('id'))->update(['status' => 'cancelled',
            'cancelled_at' => DB::raw('clock_timestamp()'), 'token_hash' => null, 'version' => DB::raw('version + 1'), 'updated_at' => DB::raw('clock_timestamp()')]);
        foreach ($rows as $row) {
            Audit::record($row->company_id, $action, $row->id, $changes, $reason);
        }
    }

    /** An inviter who loses access.manage or an invited permission in the company loses those pending invitations too. */
    public function cancelInvitationsBeyond(string $company, int $inviter, array $remaining, string $reason): void
    {
        $q = DB::table('invitations')->where('tenant_id', app(TenantContext::class)->id())->where('company_id', $company)->where('invited_by', $inviter)
            ->whereRaw("NOT (CAST(? AS jsonb) @> (permissions || '[\"access.manage\"]'::jsonb))", [json_encode(array_values($remaining))]);
        $this->cancelInvitations($q, 'invitation.cancelled', ['cause' => 'inviter_access_removed'], $reason);
    }

    public function grants(string $company, string $membership): Builder
    {
        return DB::table('company_grants')->where('tenant_id', app(TenantContext::class)->id())->where('company_id',$company)->where('membership_id',$membership);
    }
}
