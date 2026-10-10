<?php

namespace App\Http\Resources\Workforce;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The enabled private profile fields (ViewEmployeeProfile). fields is always a JSON object, {} when nothing is enabled. */
class EmployeeProfileResource extends JsonResource
{
    /** @param  array{employee_id: string, version: int, fields: array<string, mixed>}  $resource */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return ['employee_id' => $this->resource['employee_id'], 'version' => $this->resource['version'], 'fields' => (object) $this->resource['fields']];
    }
}
