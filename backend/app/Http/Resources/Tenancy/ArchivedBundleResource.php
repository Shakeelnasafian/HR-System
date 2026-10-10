<?php

namespace App\Http\Resources\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The archive acknowledgement: the bundle id as requested, always archived. */
class ArchivedBundleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource['id'], 'archived' => $this->resource['archived']];
    }
}
