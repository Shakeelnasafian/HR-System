<?php

namespace App\Actions\Tenancy;

use App\Models\Tenancy\Company;
use App\Models\Tenancy\CompanyGrant;
use App\Models\Tenancy\TenantMembership;
use App\Services\Audit\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Removes the whole tenant membership, only if the actor administers every company where the target holds grants. */
final class RevokeMembership
{
    public function __construct(private readonly ManagerAuthority $authority, private readonly CancelInviterInvitations $cancelInvitations) {}

    /**
     * @param  Company  $company  the route company, locked FOR UPDATE
     * @return array{membership_id: string, status: string, access_version: int}
     */
    public function handle(Company $company, string $membership, array $data): array
    {
        [$actor] = $this->authority->of($company);
        abort_if($actor->id === $membership, 403, 'You cannot remove yourself from the organization.');
        $target = TenantMembership::query()->withAccessTo($company->id)->whereKey($membership)->where('status', '!=', 'revoked')->first(['id', 'user_id']);
        abort_unless($target, 404);
        abort_unless($company->access_version === $data['version'], 409, 'Company permissions changed. Reload and review the latest grants.');
        $companies = CompanyGrant::query()->where('membership_id', $membership)->distinct()->orderBy('company_id')->pluck('company_id')->all();
        $managed = CompanyGrant::query()->where('membership_id', $actor->id)->where('permission', 'access.manage')->pluck('company_id')->all();
        abort_if((bool) array_diff($companies, $managed), 403, 'Target has access in companies you do not administer; remove company access instead.');
        // The definer function locks the affected companies, re-validates actor authority and the grant set, then revokes.
        try {
            $removed = DB::select('SELECT company_id, permissions FROM hr_revoke_membership(?, ?, ?::uuid[])', [$actor->id, $membership, '{'.implode(',', $companies).'}']);
        } catch (QueryException $e) {
            abort_if(($e->errorInfo[0] ?? '') === '55000', 409, 'Company access for this member changed. Reload and review it.');
            throw $e;
        }
        foreach ($removed as $item) {
            // Query builder: bump access_version without touching companies.updated_at, as before.
            DB::table('companies')->where('tenant_id', $company->tenant_id)->where('id', $item->company_id)->increment('access_version');
            $permissions = str_getcsv(trim($item->permissions, '{}'));
            Audit::record($item->company_id, 'membership.revoked', $membership, ['removed' => $permissions], $data['reason']);
            $this->cancelInvitations->handle($item->company_id, (int) $target->user_id, [], $data['reason']);
        }
        $version = DB::table('companies')->where('tenant_id', $company->tenant_id)->where('id', $company->id)->value('access_version');

        return ['membership_id' => $membership, 'status' => 'revoked', 'access_version' => $version];
    }
}
