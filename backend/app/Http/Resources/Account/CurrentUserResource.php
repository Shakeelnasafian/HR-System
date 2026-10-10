<?php

namespace App\Http\Resources\Account;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class CurrentUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'mfa_enrolled' => (bool) $this->two_factor_confirmed_at,
            // Set by the Fortify two-factor challenge listener for this session only.
            'mfa_verified' => (int) $request->session()->get('mfa_user_id') === (int) $this->id,
        ];
    }
}
