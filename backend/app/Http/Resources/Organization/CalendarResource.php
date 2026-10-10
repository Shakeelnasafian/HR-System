<?php

namespace App\Http\Resources\Organization;

use App\Models\Organization\WorkingCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A calendar without its history (create and update responses). @mixin WorkingCalendar */
class CalendarResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'archived' => $this->archived, 'version' => $this->version];
    }
}
