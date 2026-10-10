<?php

namespace App\Http\Resources\Organization;

use App\Models\Tenancy\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanySettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'code' => $this->code, 'timezone' => $this->timezone, 'version' => $this->version];
    }
}
