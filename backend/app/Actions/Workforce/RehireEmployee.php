<?php

namespace App\Actions\Workforce;

use App\Models\Workforce\Employee;
use App\Models\Workforce\Employment;
use Illuminate\Validation\ValidationException;

/** Adds another employment (draft) for a person who already has one in the company. */
final class RehireEmployee
{
    public function __construct(private readonly AddEmployment $addEmployment, private readonly ReportingLine $reportingLine) {}

    /** @param  array<string, mixed>  $data  validated employment fields and assignment references */
    public function handle(string $company, string $employee, array $data): Employment
    {
        $reporting = ! empty($data['manager_employment_id']);
        if ($reporting) {
            $this->reportingLine->lock($company);
        } // before the person lock, as for assignment changes
        abort_unless(Employee::query()->inCompany($company)->whereKey($employee)->lockForUpdate()->first(), 404);
        $data['employment_number'] = strtoupper($data['employment_number']);
        if (Employment::query()->where('company_id', $company)->where('employment_number', $data['employment_number'])->exists()) {
            throw ValidationException::withMessages(['employment_number' => 'This employment number is already in use.']);
        }
        $employment = $this->addEmployment->handle($company, $employee, $data);
        // The person may already manage someone through another employment, so a manager here can close a cycle between people.
        if ($reporting) {
            $this->reportingLine->assertAcyclic($company, $employment->id, $data['start_date'], null);
        }

        return $employment;
    }
}
