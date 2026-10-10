<?php

namespace App\Http\Requests\Workforce\Concerns;

use App\Models\Workforce\EmploymentAssignment;

/** Validation rules for a new employment with its initial assignment, and for assignment references. */
trait EmploymentRules
{
    /** @return array<string, string> */
    protected function employmentRules(): array
    {
        return ['employment_number' => 'required|string|max:40|regex:/^[A-Za-z0-9_-]+$/', 'start_date' => 'required|date_format:Y-m-d',
            'probation_end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date'] + $this->assignmentRules();
    }

    /** Every assignment key is optional; null clears it. @return array<string, string> */
    protected function assignmentRules(): array
    {
        return array_fill_keys(EmploymentAssignment::KEYS, 'sometimes|nullable|uuid');
    }
}
