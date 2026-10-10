<?php

namespace App\Http\Resources\Tenancy;

use App\Models\Tenancy\TenantMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member with access to the company (membership joined with the user's name and email) and its grants there.
 *
 * @mixin TenantMembership
 */
class CompanyMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'requires_mfa' => $this->requires_mfa, 'name' => $this->name, 'email' => $this->email,
            'permissions' => $this->grants->pluck('permission')->all()];
    }
}
