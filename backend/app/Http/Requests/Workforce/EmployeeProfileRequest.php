<?php

namespace App\Http\Requests\Workforce;

use App\Http\Requests\CompanyRequest;
use App\Models\Workforce\Employment;
use Illuminate\Support\Str;

/**
 * Private profile requests (profile.read / profile.write, both privileged). The person must have a draft or active
 * employment in the route company; ended/cancelled-only relationships are 404 (former-employee profile access awaits
 * an HR/legal policy decision). Checked before validation.
 */
abstract class EmployeeProfileRequest extends CompanyRequest
{
    public function authorize(): bool
    {
        $employee = $this->employeeId();

        return parent::authorize() && Str::isUuid($employee)
            && Employment::query()->where('company_id', $this->companyId())->where('employee_id', $employee)->whereIn('status', ['draft', 'active'])->exists();
    }

    public function employeeId(): string
    {
        return (string) $this->route('employee');
    }
}
