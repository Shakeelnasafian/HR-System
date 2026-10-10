<?php

namespace App\Actions\Workforce;

use App\Models\Workforce\Employment;
use App\Models\Workforce\EmploymentAssignment;
use Illuminate\Validation\ValidationException;

/** Validates the references of a new assignment row (shared by employment creation and assignment changes). */
final class AssignmentReferences
{
    /**
     * References must be active same-company records; a manager must be another person's draft/active employment started by $date.
     * Keys in $inherited were copied forward rather than supplied: they are re-validated (never silently cleared) with a distinct message.
     */
    public function assertValid(string $company, array $refs, string $date, string $employee, array $inherited = []): void
    {
        $errors = [];
        foreach (EmploymentAssignment::REFERENCES as $key => $model) {
            if (! empty($refs[$key]) && ! $model::query()->where('company_id', $company)->whereKey($refs[$key])->where('archived', false)->sharedLock()->first()) {
                $errors[$key] = 'Select an active record in this company.';
            }
        }
        if (! empty($refs['manager_employment_id'])) {
            $q = Employment::query()->where('company_id', $company)->whereKey($refs['manager_employment_id']);
            // An inherited manager is only re-read: locking it outside the reporting-line lock could deadlock with the manager's own edit.
            $manager = (in_array('manager_employment_id', $inherited, true) ? $q : $q->sharedLock())->first();
            if (! $manager || ! in_array($manager->status, ['draft', 'active'], true) || $manager->start_date > $date) {
                $errors['manager_employment_id'] = 'Select a draft or active employment in this company that starts by the effective date.';
            } elseif ($manager->employee_id === $employee) {
                $errors['manager_employment_id'] = 'An employee cannot be their own manager.';
            }
        }
        foreach (array_intersect_key($errors, array_flip($inherited)) as $key => $message) {
            $errors[$key] = 'The current value is no longer active. Choose another or clear it explicitly.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
