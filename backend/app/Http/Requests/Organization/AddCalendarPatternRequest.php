<?php

namespace App\Http\Requests\Organization;

class AddCalendarPatternRequest extends CalendarChangeRequest
{
    /** Pattern input, shared with calendar creation (which always writes the first pattern). */
    public const PATTERN = ['effective_from' => 'required|date_format:Y-m-d', 'working_days' => 'required|array|min:1|max:7', 'working_days.*' => 'required|integer|between:1,7|distinct'];

    protected function changeRules(): array
    {
        return self::PATTERN;
    }
}
