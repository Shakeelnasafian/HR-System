<?php

namespace App\Http\Requests\Organization;

class AddCalendarHolidayRequest extends CalendarChangeRequest
{
    protected function changeRules(): array
    {
        return ['holiday_date' => 'required|date_format:Y-m-d', 'name' => 'required|string|max:120'];
    }
}
