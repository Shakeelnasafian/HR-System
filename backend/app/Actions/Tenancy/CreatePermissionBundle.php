<?php

namespace App\Actions\Tenancy;

use App\Models\Tenancy\Company;
use App\Models\Tenancy\PermissionBundle;
use App\Services\Audit\Audit;
use Illuminate\Validation\ValidationException;

/** Immutable templates: copying a bundle never creates a live role assignment. */
final class CreatePermissionBundle
{
    public function __construct(private readonly ManagerAuthority $authority) {}

    /** @param  Company  $company  the route company, locked FOR UPDATE */
    public function handle(Company $company, array $data): PermissionBundle
    {
        [, $held] = $this->authority->of($company);
        abort_if((bool) array_diff($data['permissions'], $held), 403, 'A bundle can contain only permissions you currently hold in this company.');
        if (! in_array('company.read', $data['permissions'], true)) {
            throw ValidationException::withMessages(['permissions' => 'Company access is required in every bundle.']);
        }
        $bundles = fn () => PermissionBundle::query()->where('company_id', $company->id);
        abort_if($bundles()->where('archived', false)->count() >= 100, 409, 'Archive an unused bundle before adding another.');
        $name = trim($data['name']);
        // Names stay reserved after archive. The company lock serializes writers; the lower(name) index backs it up.
        if ($bundles()->whereRaw('lower(name)=lower(?)', [$name])->exists()) {
            throw ValidationException::withMessages(['name' => 'This name is already used by a current or archived bundle. Choose a new name.']);
        }
        $permissions = $data['permissions'];
        sort($permissions);
        $bundle = PermissionBundle::create(['company_id' => $company->id, 'name' => $name, 'permissions' => $permissions]);
        Audit::record($company->id, 'permission_bundle.created', $bundle->id, ['permissions' => $permissions], $data['reason']);

        return $bundle;
    }
}
