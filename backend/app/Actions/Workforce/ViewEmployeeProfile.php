<?php

namespace App\Actions\Workforce;

use App\Models\Workforce\EmployeeProfile;
use App\Services\Audit\Audit;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Reads the company's enabled private profile fields. Every read is audited with field names only. */
final class ViewEmployeeProfile
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array{employee_id: string, version: int, fields: array<string, mixed>} */
    public function handle(string $company, string $employee): array
    {
        $enabled = $this->enabledFields($company);
        $row = EmployeeProfile::query()->where('employee_id', $employee)->first();
        $fields = [];
        foreach ($enabled as $field) {
            $fields[$field] = $row?->fieldValue($field);
        }
        Audit::record($company, 'profile.viewed', $employee, ['fields' => $enabled]);

        return ['employee_id' => $employee, 'version' => $row->version ?? 0, 'fields' => $fields];
    }

    private function enabledFields(string $company): array
    {
        // FOR SHARE on the company row's jsonb setting: a concurrent field-configuration change waits for this request instead of racing it.
        return json_decode(DB::table('companies')->where('tenant_id', $this->context->id())->where('id', $company)->sharedLock()->value('profile_fields'), true);
    }
}
