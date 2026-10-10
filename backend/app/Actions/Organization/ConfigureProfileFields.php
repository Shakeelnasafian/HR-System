<?php

namespace App\Actions\Organization;

use App\Models\Tenancy\Company;
use App\Models\Workforce\EmployeeProfile;
use App\Services\Audit\Audit;

/**
 * Chooses which private-profile fields the company collects. Enabled fields are stored in display order; disabling a field
 * hides its stored values without deleting them. Unchanged settings neither bump the version nor audit.
 */
class ConfigureProfileFields
{
    /** @param  Company  $company  the route company, locked FOR UPDATE with company.manage re-evaluated */
    public function handle(Company $company, array $data): Company
    {
        // Strict, as before: the validated version is compared as submitted.
        abort_unless($company->profile_fields_version === $data['version'], 409, 'Profile field settings changed. Reload before saving.');
        $before = $company->profile_fields;
        $after = array_values(array_intersect(EmployeeProfile::FIELDS, $data['enabled']));
        if ($before !== $after) {
            $company->forceFill(['profile_fields' => $after, 'profile_fields_version' => $company->profile_fields_version + 1])->save();
            Audit::record($company->id, 'profile_fields.updated', $company->id, ['enabled' => array_values(array_diff($after, $before)), 'disabled' => array_values(array_diff($before, $after))], $data['reason']);
        }

        return $company;
    }
}
