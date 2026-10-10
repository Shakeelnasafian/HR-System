<?php

namespace App\Http\Resources\Workforce;

use App\Models\Workforce\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Directory fields safe for workforce.read (never profile data). @mixin Employee */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'employee_number' => $this->employee_number, 'legal_name' => $this->legal_name, 'preferred_name' => $this->preferred_name];
    }
}
