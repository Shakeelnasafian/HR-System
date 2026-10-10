<?php

namespace App\Actions\Tenancy;

use App\Models\Tenancy\Company;
use App\Models\Tenancy\CompanyGrant;
use App\Models\Tenancy\TenantMembership;
use App\Services\Audit\Audit;
use App\Services\Tenancy\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Sets a member's permissions in one company, changing only the difference the actor is allowed to delegate. */
final class ReplaceCompanyAccess
{
    public function __construct(private readonly ManagerAuthority $authority, private readonly CancelInviterInvitations $cancelInvitations) {}

    /**
     * @param  Company  $company  the route company, locked FOR UPDATE (serializes all grant mutations in the company)
     * @return array{membership_id: string, permissions: list<string>, access_version: int}
     */
    public function handle(Company $company, string $membership, array $data): array
    {
        // Evaluate the actor's current grants again under the company lock.
        [$actor, $held] = $this->authority->of($company);
        abort_if($actor->id === $membership, 403, 'You cannot change your own permissions. Ask another authorized administrator.');
        // Lock order company → member (same as revoke and invitation acceptance); read the target only after both.
        DB::select('SELECT hr_lock_membership_mfa_policy(?::uuid)', [$membership]);
        $target = TenantMembership::query()->withAccessTo($company->id)->whereKey($membership)->first(['id', 'status', 'requires_mfa', 'user_id']);
        abort_unless($target, 404);
        abort_unless($company->access_version === $data['version'], 409, 'Company permissions changed. Reload and review the latest grants.');
        abort_unless($target->status === 'active', 409, 'This membership is inactive. An operator must review it first.');
        $current = CompanyGrant::query()->for($company->id, $membership)->pluck('permission')->all();
        $desired = $data['permissions'];
        sort($desired);
        $added = array_values(array_diff($desired, $current));
        $removed = array_values(array_diff($current, $desired));
        abort_if(count(array_diff(array_merge($added, $removed), $held)) > 0, 403, 'You can change only permissions you currently hold in this company.');
        if ($desired && ! in_array('company.read', $desired, true)) {
            throw ValidationException::withMessages(['permissions' => 'Company access is required when other permissions are assigned.']);
        }
        if (PermissionCatalog::requiresMfa($desired) && ! $target->requires_mfa) {
            throw ValidationException::withMessages(['permissions' => 'An operator must require MFA on this membership before privileged access can be assigned.']);
        }
        $version = $company->access_version;
        if ($added || $removed) {
            // Preserve existing grants; only the authorized difference is changed.
            CompanyGrant::query()->for($company->id, $membership)->whereIn('permission', $removed)->delete();
            foreach ($added as $permission) {
                CompanyGrant::query()->insert(['tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'membership_id' => $membership, 'permission' => $permission]);
            }
            // Query builder: bump access_version without touching companies.updated_at, as before.
            DB::table('companies')->where('tenant_id', $company->tenant_id)->where('id', $company->id)->update(['access_version' => $version + 1]);
            Audit::record($company->id, 'membership.company_permissions.updated', $membership, ['added' => $added, 'removed' => $removed], $data['reason']);
            if ($removed) {
                $this->cancelInvitations->handle($company->id, (int) $target->user_id, $desired, $data['reason']);
            }
            $version++;
        }

        return ['membership_id' => $membership, 'permissions' => $desired, 'access_version' => $version];
    }
}
