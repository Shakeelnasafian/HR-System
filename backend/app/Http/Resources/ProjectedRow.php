<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Query builder projections are stdClass rows, not Eloquent models. */
final class ProjectedRow extends JsonResource
{
    public function toArray(Request $request): array
    {
        return (array) $this->resource;
    }
}
