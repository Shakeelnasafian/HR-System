<?php

namespace App\Http\Resources\Tenancy;

use App\Models\Tenancy\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invitation as loaded with Invitation::presented() (status already reports lapsed pending rows as expired).
 * Never includes token_hash.
 *
 * @mixin Invitation
 */
class InvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'permissions' => $this->permissions, 'requires_mfa' => $this->requires_mfa, 'status' => $this->status,
            'expires_at' => $this->getRawOriginal('expires_at'), 'created_at' => $this->getRawOriginal('created_at'), 'version' => $this->version];
    }
}
