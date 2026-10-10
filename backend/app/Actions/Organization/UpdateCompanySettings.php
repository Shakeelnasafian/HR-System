<?php

namespace App\Actions\Organization;

use App\Models\Tenancy\Company;
use App\Services\Audit\Audit;
use Illuminate\Validation\ValidationException;

/** The company timezone defines "today" for employment activation and calendar patterns. Code is immutable. */
class UpdateCompanySettings
{
    /** @param  Company  $company  the route company, locked FOR UPDATE with company.manage re-evaluated */
    public function handle(Company $company, array $data): Company
    {
        if (! array_key_exists('name', $data) && ! array_key_exists('timezone', $data)) {
            throw ValidationException::withMessages(['name' => 'Change the name or the timezone.']);
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            if ($data['name'] === '') {
                throw ValidationException::withMessages(['name' => 'The name is required.']);
            }
        }
        abort_unless((int) $company->version === (int) $data['version'], 409, 'This company changed. Reload before saving.');
        $changes = array_filter(array_intersect_key($data, ['name' => 1, 'timezone' => 1]), fn ($value, $key) => $value !== $company->$key, ARRAY_FILTER_USE_BOTH);
        if ($changes) {
            $company->forceFill($changes + ['version' => $company->version + 1])->save();
            Audit::record($company->id, 'company.updated', $company->id, ['fields' => array_keys($changes)], $data['reason']);
        }

        return $company;
    }
}
