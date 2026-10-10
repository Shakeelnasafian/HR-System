<?php

namespace App\Http\Resources\Workforce;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The outcome of UpdateEmployeeProfile: never values, only the new version and the updated (or submitted) keys. */
class EmployeeProfileUpdateResource extends JsonResource
{
    /** @param  array{employee_id: string, version: int, updated: list<string>}  $resource */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return ['employee_id' => $this->resource['employee_id'], 'version' => $this->resource['version'], 'updated' => $this->resource['updated']];
    }
}
