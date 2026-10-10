<?php

namespace App\Actions\Organization;

use App\Models\Organization\OrganizationUnit;
use App\Models\Tenancy\Company;
use App\Services\Audit\Audit;
use Illuminate\Validation\ValidationException;

/** Codes are stored upper-case and are unique per company and kind. */
class CreateOrganizationUnit
{
    /** @param  class-string<OrganizationUnit>  $model */
    public function handle(Company $company, string $kind, string $model, array $data): OrganizationUnit
    {
        $data['code'] = strtoupper($data['code']);
        if ($model::query()->where('company_id', $company->id)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'This code is already in use.']);
        }
        $unit = $model::create($data + ['company_id' => $company->id]);
        Audit::record($company->id, $kind.'.created', $unit->id, ['fields' => array_keys($data)]);

        // Re-read for the database defaults (archived, version).
        return $model::query()->where('company_id', $company->id)->whereKey($unit->id)->firstOrFail();
    }
}
