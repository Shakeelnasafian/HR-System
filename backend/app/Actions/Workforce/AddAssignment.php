<?php

namespace App\Actions\Workforce;

use App\Models\Workforce\Employment;
use App\Models\Workforce\EmploymentAssignment;
use App\Services\Audit\Audit;
use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Appends an effective-dated assignment. Unspecified fields copy from the assignment in effect on effective_from;
 * explicit null clears. The returned row carries the updated employment (employment_version).
 */
final class AddAssignment
{
    public function __construct(
        private readonly AssignmentReferences $references,
        private readonly ReportingLine $reportingLine,
        private readonly TenantContext $context,
    ) {}

    /** @param  array<string, mixed>  $data  validated version, reason, effective_from and assignment keys */
    public function handle(string $company, string $employment, array $data): EmploymentAssignment
    {
        $explicit = array_intersect_key($data, array_flip(EmploymentAssignment::KEYS));
        $date = $data['effective_from'];
        $reporting = ! empty($explicit['manager_employment_id']);
        if ($reporting) {
            $this->reportingLine->lock($company);
        } // before the row lock, always in this order
        $row = Employment::query()->where('company_id', $company)->whereKey($employment)->lockForUpdate()->first();
        abort_unless($row, 404);
        abort_unless($row->version === $data['version'], 409, 'This employment changed. Reload before continuing.');
        abort_if($row->status === 'cancelled', 409, 'Cancelled employments cannot change assignments.');
        if ($date < $row->start_date || ($row->end_date && $date >= $row->end_date)) {
            throw ValidationException::withMessages(['effective_from' => 'The effective date must fall within the employment (from start date, before end date).']);
        }
        $history = fn () => EmploymentAssignment::query()->where('company_id', $company)->where('employment_id', $row->id);
        if ($history()->where('effective_from', $date)->exists()) {
            throw ValidationException::withMessages(['effective_from' => 'An assignment already starts on this date.']);
        }
        $base = $history()->effectiveOn($date)->latestFirst()->first();
        $before = array_map(fn ($k) => $base?->$k, array_combine(EmploymentAssignment::KEYS, EmploymentAssignment::KEYS));
        $values = array_merge($before, $explicit);
        // Copied-forward references are re-validated too: a new row must not re-assert an archived record or an ended/cancelled manager.
        $this->references->assertValid($company, $values, $date, $row->employee_id, array_keys(array_diff_key($values, $explicit)));
        $assignment = EmploymentAssignment::create(['company_id' => $company, 'employment_id' => $row->id, 'effective_from' => $date,
            'created_by' => $this->context->userId(), 'reason' => $data['reason']] + $values);
        if ($reporting) {
            $next = $history()->where('effective_from', '>', $date)->min('effective_from');
            $this->reportingLine->assertAcyclic($company, $row->id, $date, $next);
        }
        $row->forceFill(['version' => $row->version + 1])->save();
        $changed = array_keys(array_filter($values, fn ($v, $k) => $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
        Audit::record($company, 'employment.assignment_added', $row->id, ['assignment_id' => $assignment->id, 'effective_from' => $date, 'fields' => $changed], $data['reason']);

        return EmploymentAssignment::query()->withDirectory()->where('employment_assignments.company_id', $company)->whereKey($assignment->id)->first()
            ->setRelation('employment', $row);
    }
}
