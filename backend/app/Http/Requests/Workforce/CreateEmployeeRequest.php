<?php

namespace App\Http\Requests\Workforce;

use App\Http\Requests\CompanyRequest;
use App\Http\Requests\Workforce\Concerns\EmploymentRules;
use Illuminate\Support\Facades\Validator;

class CreateEmployeeRequest extends CompanyRequest
{
    use EmploymentRules;

    private array $employment = [];

    protected function permission(): string
    {
        return 'workforce.write';
    }

    public function rules(): array
    {
        return ['employee_number' => 'required|string|max:40|regex:/^[A-Za-z0-9_-]+$/', 'legal_name' => 'required|string|max:160', 'preferred_name' => 'nullable|string|max:160'];
    }

    /** The first employment is validated once the person passes, as a second stage (errors are reported per stage). */
    protected function passedValidation(): void
    {
        $this->employment = Validator::make($this->all(), $this->employmentRules())->validate();
    }

    /** Validated employment fields and initial assignment references. */
    public function employmentData(): array
    {
        return $this->employment;
    }
}
