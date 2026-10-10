<?php

namespace App\Http\Resources\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** The permission codes the principal holds in the company, sorted: a JSON list of strings. */
class CompanyCapabilitiesResource extends JsonResource
{
    /** @param  Collection<int, string>  $resource */
    public function __construct(Collection $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return $this->resource->values()->all();
    }
}
