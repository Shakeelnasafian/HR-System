<?php

namespace App\Actions\Workforce;

use App\Models\Workforce\Employment;
use App\Models\Workforce\EmploymentAssignment;
use App\Services\Audit\Audit;
use App\Services\Tenancy\TenantContext;

/**
 * Creates a draft employment together with its initial assignment at start_date. Callers check the employment number
 * first; a rehire with a manager also needs RehireEmployee's cycle check.
 */
final class AddEmployment
{
    public function __construct(private readonly AssignmentReferences $references, private readonly TenantContext $context) {}

    /** @param  array<string, mixed>  $data  validated employment fields and assignment references */
    public function handle(string $company, string $employee, array $data): Employment
    {
        $refs = array_intersect_key($data, array_flip(EmploymentAssignment::KEYS));
        $this->references->assertValid($company, $refs, $data['start_date'], $employee);
        $employment = Employment::create(array_diff_key($data, $refs) + ['company_id' => $company, 'employee_id' => $employee]);
        $assignment = EmploymentAssignment::create(['company_id' => $company, 'employment_id' => $employment->id, 'effective_from' => $data['start_date'],
            'created_by' => $this->context->userId(), 'reason' => null] + $refs);
        Audit::record($company, 'employment.created', $employment->id, ['employee_id' => $employee, 'status' => 'draft', 'assignment_id' => $assignment->id,
            'fields' => array_keys(array_filter($data, fn ($v) => $v !== null))]);

        return $employment;
    }
}
