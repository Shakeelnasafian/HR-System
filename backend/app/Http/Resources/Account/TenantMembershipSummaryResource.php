<?php

namespace App\Http\Resources\Account;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A tenant the user may select: the tenant id and name with the membership's MFA requirement. */
class TenantMembershipSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'requires_mfa' => $this->requires_mfa];
    }
}
