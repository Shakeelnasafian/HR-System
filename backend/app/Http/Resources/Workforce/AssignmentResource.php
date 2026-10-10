<?php

namespace App\Http\Resources\Workforce;

use App\Models\Workforce\EmploymentAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An assignment row loaded with EmploymentAssignment::withDirectory(): each reference as {id, code, name} (or null)
 * and the manager's safe directory fields. @mixin EmploymentAssignment
 */
class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $a = $this->resource;
        $out = ['id' => $a->id, 'employment_id' => $a->employment_id, 'effective_from' => $a->effective_from];
        foreach (array_keys(EmploymentAssignment::REFERENCES) as $key) {
            $out[substr($key, 0, -3)] = $a->getAttribute($key) ? ['id' => $a->getAttribute($key), 'code' => $a->getAttribute($key.'_code'), 'name' => $a->getAttribute($key.'_name')] : null;
        }
        $out['manager'] = $a->manager_employment_id ? ['employment_id' => $a->manager_employment_id, 'employment_number' => $a->manager_employment_number, 'employee_id' => $a->manager_employee_id,
            'employee_number' => $a->manager_employee_number, 'legal_name' => $a->manager_legal_name, 'preferred_name' => $a->manager_preferred_name] : null;

        return $out + ['reason' => $a->reason, 'created_at' => $a->getRawOriginal('created_at')];
    }
}
