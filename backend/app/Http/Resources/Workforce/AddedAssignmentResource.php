<?php

namespace App\Http\Resources\Workforce;

use Illuminate\Http\Request;

/** A newly added assignment with the employment's new version (the employment relation set by AddAssignment). */
class AddedAssignmentResource extends AssignmentResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + ['employment_version' => $this->resource->employment->version];
    }
}
