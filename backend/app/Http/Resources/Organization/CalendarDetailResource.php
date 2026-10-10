<?php

namespace App\Http\Resources\Organization;

use App\Models\Organization\WorkingCalendar;
use Illuminate\Http\Request;

/**
 * A calendar with its full pattern history (newest first) and its holidays (by date). The caller loads the patterns
 * (withWorkingDays()) and the holidays relations, already filtered and ordered.
 *
 * @mixin WorkingCalendar
 */
class CalendarDetailResource extends CalendarResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'patterns' => CalendarPatternResource::collection($this->patterns),
            'holidays' => CalendarHolidayResource::collection($this->holidays),
        ];
    }
}
