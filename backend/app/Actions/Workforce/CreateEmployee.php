<?php

namespace App\Actions\Workforce;

use App\Models\Workforce\Employee;
use App\Models\Workforce\Employment;
use App\Services\Audit\Audit;
use Illuminate\Validation\ValidationException;

/** Creates a person with their first employment (draft) and its initial assignment. */
final class CreateEmployee
{
    public function __construct(private readonly AddEmployment $addEmployment) {}

    /**
     * @param  array<string, mixed>  $person  validated directory fields
     * @param  array<string, mixed>  $employment  validated employment fields and assignment references
     */
    public function handle(string $company, array $person, array $employment): Employment
    {
        $person['employee_number'] = strtoupper($person['employee_number']);
        $employment['employment_number'] = strtoupper($employment['employment_number']);
        if (Employment::query()->where('company_id', $company)->where('employment_number', $employment['employment_number'])->exists()) {
            throw ValidationException::withMessages(['employment_number' => 'This employment number is already in use.']);
        }
        if (Employee::query()->where('employee_number', $person['employee_number'])->exists()) {
            throw ValidationException::withMessages(['employee_number' => 'This employee number is unavailable.']);
        }
        $employee = Employee::create($person);
        Audit::record($company, 'employee.created', $employee->id, ['fields' => array_keys($person)]);

        return $this->addEmployment->handle($company, $employee->id, $employment);
    }
}
