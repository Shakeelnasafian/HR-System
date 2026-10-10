<?php

namespace App\Http\Requests\Organization;

class UpdateCalendarRequest extends CalendarChangeRequest
{
    protected function changeRules(): array
    {
        return ['name' => 'sometimes|required|string|max:120', 'archived' => 'sometimes|required|boolean'];
    }
}
