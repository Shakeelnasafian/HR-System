<?php

namespace App\Http\Resources\Workforce;

use App\Models\Workforce\Employment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** An employment with its current assignment (set by App\Actions\Workforce\CurrentAssignments). @mixin Employment */
class EmploymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $current = $this->resource->getRelation('currentAssignment');

        return ['id' => $this->id, 'employment_number' => $this->employment_number, 'start_date' => $this->start_date, 'end_date' => $this->end_date,
            'status' => $this->status, 'version' => $this->version, 'probation_end_date' => $this->probation_end_date,
            'current_assignment' => $current ? new AssignmentResource($current) : null];
    }
}
