<?php

namespace App\Http\Resources\Tenancy;

use App\Models\Tenancy\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyListItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'code' => $this->code, 'timezone' => $this->timezone];
    }
}
