<?php

namespace App\Http\Resources\Workforce;

use App\Models\Workforce\Employment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The ids of a new person and their first employment, from that employment. @mixin Employment */
class EmployeeCreatedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->employee_id, 'employment_id' => $this->id];
    }
}
