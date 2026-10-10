<?php

namespace App\Http\Resources\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** What an invitation link may reveal before acceptance (the hr_preview_invitation row): never the full email. */
class InvitationPreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['tenant_name' => $this->resource->tenant_name, 'company_name' => $this->resource->company_name, 'email_hint' => $this->resource->email_hint,
            'expires_at' => Carbon::parse($this->resource->expires_at)->toIso8601String(), 'existing_account' => (bool) $this->resource->existing_account];
    }
}
