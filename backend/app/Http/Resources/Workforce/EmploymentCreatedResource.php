<?php

namespace App\Http\Resources\Workforce;

use App\Models\Workforce\Employment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Employment */
class EmploymentCreatedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id];
    }
}
