<?php

namespace App\Http\Resources\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Where an accepted invitation leads: the tenant from the link, the company and whether MFA is required there. */
class AcceptedInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['tenant_id' => $this->resource['tenant_id'], 'company_id' => $this->resource['company_id'], 'requires_mfa' => $this->resource['requires_mfa']];
    }
}
