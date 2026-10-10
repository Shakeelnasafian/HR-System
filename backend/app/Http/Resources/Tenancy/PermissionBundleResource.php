<?php

namespace App\Http\Resources\Tenancy;

use App\Models\Tenancy\PermissionBundle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PermissionBundle */
class PermissionBundleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'permissions' => $this->permissions];
    }
}
