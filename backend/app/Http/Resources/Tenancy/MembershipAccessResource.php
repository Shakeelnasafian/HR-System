<?php

namespace App\Http\Resources\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Result of replacing a member's company permissions: the membership id as requested and the new access version. */
class MembershipAccessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['membership_id' => $this->resource['membership_id'], 'permissions' => $this->resource['permissions'], 'access_version' => $this->resource['access_version']];
    }
}
