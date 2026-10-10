<?php

namespace App\Actions\Workforce;

use App\Models\Tenancy\Company;
use App\Models\Workforce\EmployeeProfile;
use App\Services\Audit\Audit;

/** Reads the company's enabled private profile fields. Every read is audited with field names only. */
final class ViewEmployeeProfile
{
    /** @return array{employee_id: string, version: int, fields: array<string, mixed>} */
    public function handle(string $company, string $employee): array
    {
        $enabled = Company::sharedProfileFields($company);
        $row = EmployeeProfile::query()->where('employee_id', $employee)->first();
        $fields = [];
        foreach ($enabled as $field) {
            $fields[$field] = $row?->fieldValue($field);
        }
        Audit::record($company, 'profile.viewed', $employee, ['fields' => $enabled]);

        return ['employee_id' => $employee, 'version' => $row->version ?? 0, 'fields' => $fields];
    }
}
