<?php

namespace App\Http\Resources\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Result of revoking a tenant membership: the membership id as requested and the route company's new access version. */
class RevokedMembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['membership_id' => $this->resource['membership_id'], 'status' => $this->resource['status'], 'access_version' => $this->resource['access_version']];
    }
}
