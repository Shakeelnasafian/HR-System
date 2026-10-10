<?php

namespace App\Http\Requests\Organization;

use Illuminate\Support\Str;

/** The holiday row is resolved after the calendar lock; only a malformed id is rejected up front. */
class RemoveCalendarHolidayRequest extends CalendarChangeRequest
{
    /** A malformed holiday id is a 404 before the company is authorized (so before any MFA 403). */
    public function authorize(): bool
    {
        abort_unless(Str::isUuid($this->holidayId()), 404);

        return parent::authorize();
    }

    public function holidayId(): string
    {
        return (string) $this->route('holiday');
    }
}
