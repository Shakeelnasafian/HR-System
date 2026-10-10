<?php

namespace App\Http\Resources\Workforce;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A direct report row projected by DirectReportController (query builder stdClass): safe directory fields only. */
class DirectReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return ['employment_id' => $r->employment_id, 'employment_number' => $r->employment_number, 'status' => $r->status, 'employee_id' => $r->employee_id,
            'employee_number' => $r->employee_number, 'legal_name' => $r->legal_name, 'preferred_name' => $r->preferred_name];
    }
}
