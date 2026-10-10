<?php

namespace App\Http\Resources\Organization;

use App\Models\Organization\OrganizationUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganizationUnit */
class OrganizationUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'archived' => $this->archived, 'version' => $this->version];
    }
}
