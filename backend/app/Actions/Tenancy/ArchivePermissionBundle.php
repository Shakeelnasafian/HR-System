<?php

namespace App\Actions\Tenancy;

use App\Models\Tenancy\Company;
use App\Models\Tenancy\PermissionBundle;
use App\Services\Audit\Audit;

/** Archiving hides a bundle from the picker; its name stays reserved and no live grant changes. Idempotent. */
final class ArchivePermissionBundle
{
    public function __construct(private readonly ManagerAuthority $authority) {}

    /** @param  Company  $company  the route company, locked FOR UPDATE */
    public function handle(Company $company, string $bundle, string $reason): PermissionBundle
    {
        [, $held] = $this->authority->of($company);
        $row = PermissionBundle::query()->where('company_id', $company->id)->whereKey($bundle)->firstOr(fn () => abort(404));
        abort_if((bool) array_diff($row->permissions, $held), 403, 'You cannot archive a bundle outside your current authority.');
        if (! $row->archived) {
            $row->forceFill(['archived' => true])->save();
            Audit::record($company->id, 'permission_bundle.archived', $bundle, [], $reason);
        }

        return $row;
    }
}
