<?php

namespace App\Http\Resources\Organization;

use App\Models\Organization\CalendarPattern;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A pattern selected withWorkingDays(): working_days is rendered as a list of ISO weekdays. @mixin CalendarPattern */
class CalendarPatternResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'effective_from' => $this->effective_from, 'working_days' => $this->workingDays()];
    }
}
