<?php

namespace App\Http\Requests\Workforce;

use App\Http\Requests\CompanyRequest;
use App\Http\Requests\Workforce\Concerns\EmploymentRules;
use App\Models\Workforce\Employee;
use Illuminate\Support\Str;

class RehireEmployeeRequest extends CompanyRequest
{
    use EmploymentRules;

    protected function permission(): string
    {
        return 'workforce.write';
    }

    /** The person must already have an employment in the company: otherwise 404, before validation. */
    public function authorize(): bool
    {
        $id = (string) $this->route('id');

        return parent::authorize() && Str::isUuid($id) && Employee::query()->inCompany($this->companyId())->whereKey($id)->exists();
    }

    public function rules(): array
    {
        return $this->employmentRules();
    }
}
