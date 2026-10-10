<?php

namespace App\Http\Resources\Workforce;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The outcome of TransitionEmployment: the requested employment id, its new status and version. */
class EmploymentTransitionResource extends JsonResource
{
    /** @param  array{id: string, status: string, version: int}  $resource */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return ['id' => $this->resource['id'], 'status' => $this->resource['status'], 'version' => $this->resource['version']];
    }
}
