<?php

namespace App\Actions\Workforce;

use App\Models\Tenancy\Company;
use App\Models\Workforce\Employee;
use App\Models\Workforce\EmployeeProfile;
use App\Services\Audit\Audit;
use App\Services\Tenancy\CompanyAccess;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Writes private profile fields. version 0 means no profile row exists yet. Nulls clear a value. The result never
 * contains values (profile.write does not imply profile.read). Every accepted write, including a no-op, bumps the
 * version and is audited with submitted and changed keys; only actors who may also read the profile learn which keys
 * actually changed, so a write-only actor cannot confirm guessed values.
 */
final class UpdateEmployeeProfile
{
    private const RULES = [
        'birth_date' => 'nullable|date_format:Y-m-d', 'nationality' => 'nullable|string|max:100',
        'personal_email' => 'nullable|string|email|max:254', 'personal_phone' => 'nullable|string|max:50', 'address' => 'nullable|string|max:1000',
        'emergency_contacts' => 'nullable|array|list|max:5', 'emergency_contacts.*' => 'required|array:name,relationship,phone',
        'emergency_contacts.*.name' => 'required|string|max:120', 'emergency_contacts.*.relationship' => 'required|string|max:60', 'emergency_contacts.*.phone' => 'required|string|max:50',
    ];

    public function __construct(private readonly TenantContext $context, private readonly CompanyAccess $access) {}

    /**
     * @param  array<string, mixed>  $input  the request input (version, reason and the submitted fields map)
     * @return array{employee_id: string, version: int, updated: list<string>}
     */
    public function handle(Company $company, string $employee, array $input): array
    {
        $enabled = Company::sharedProfileFields($company->id);
        $submittedFields = $input['fields'];
        $foreign = array_diff(array_keys($submittedFields), $enabled);
        if ($foreign) {
            throw ValidationException::withMessages(array_fill_keys(array_map(fn ($k) => "fields.$k", $foreign), 'This field is not collected by this company.'));
        }
        $rules = array_filter(['birth_date' => 'nullable|date_format:Y-m-d|before_or_equal:'.$company->today()] + self::RULES, fn ($k) => in_array(explode('.', $k)[0], $enabled, true), ARRAY_FILTER_USE_KEY);
        $values = array_intersect_key(Validator::make($input, array_combine(array_map(fn ($k) => "fields.$k", array_keys($rules)), $rules))->validate()['fields'] ?? [], $submittedFields);
        if (isset($values['emergency_contacts'])) {
            $values['emergency_contacts'] = EmployeeProfile::contacts($values['emergency_contacts']);
        }
        // The employee row is the per-person aggregate lock (shared with employment transitions); it also serializes first creation.
        Employee::query()->whereKey($employee)->lockForUpdate()->first();
        $row = EmployeeProfile::query()->where('employee_id', $employee)->lockForUpdate()->first();
        $version = $row->version ?? 0;
        abort_unless($version === (int) $input['version'], 409, 'This profile changed. Reload before saving.');
        $submitted = array_keys($values);
        $changed = array_keys(array_filter($values, fn ($v, $k) => $row?->fieldValue($k) !== $v, ARRAY_FILTER_USE_BOTH));
        $store = array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, array_intersect_key($values, array_flip($changed)));
        // Query-builder writes: Eloquent's dirty check treats numeric strings such as phone numbers as equal ('0100' == '100').
        if ($row) {
            EmployeeProfile::query()->where('employee_id', $employee)->update($store + ['version' => ++$version, 'updated_at' => now()]);
        } else {
            EmployeeProfile::query()->insert($store + ['tenant_id' => $this->context->id(), 'employee_id' => $employee, 'version' => ++$version, 'created_at' => now(), 'updated_at' => now()]);
        }
        Audit::record($company->id, 'profile.updated', $employee, ['fields' => $changed, 'submitted' => $submitted], $input['reason']);
        $reader = $this->access->allows($company->id, 'profile.read');

        return ['employee_id' => $employee, 'version' => $version, 'updated' => $reader ? $changed : $submitted];
    }
}
