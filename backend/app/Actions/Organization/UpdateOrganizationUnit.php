<?php

namespace App\Actions\Organization;

use App\Models\Organization\OrganizationUnit;
use App\Models\Tenancy\Company;
use App\Services\Audit\Audit;

/** Every accepted update bumps the version, even when nothing else changes. */
class UpdateOrganizationUnit
{
    /** @param  class-string<OrganizationUnit>  $model */
    public function handle(Company $company, string $kind, string $model, string $id, array $data): OrganizationUnit
    {
        $unit = $model::query()->where('company_id', $company->id)->whereKey($id)->lockForUpdate()->first();
        abort_unless($unit, 404);
        // Strict, as before: the validated version is compared as submitted.
        abort_unless($unit->version === $data['version'], 409, 'This record changed. Reload before saving.');
        unset($data['version']);
        $unit->forceFill($data + ['version' => $unit->version + 1])->save();
        Audit::record($company->id, $kind.'.updated', $id, ['fields' => array_keys($data)]);

        return $unit;
    }
}
