<?php

namespace App\Http\Resources\Organization;

use App\Models\Organization\CalendarHoliday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CalendarHoliday */
class CalendarHolidayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'holiday_date' => $this->holiday_date, 'name' => $this->name];
    }
}
