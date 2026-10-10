<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\CompanyRequest;

/**
 * A versioned change to one calendar. The calendar is resolved and locked after validation
 * (App\Actions\Organization\LockCalendar), so an unknown calendar with invalid input is a 422.
 */
abstract class CalendarChangeRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'organization.write';
    }

    /** @return array<string, mixed> */
    protected function changeRules(): array
    {
        return [];
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'reason' => 'required|string|max:500'] + $this->changeRules();
    }

    public function calendarId(): string
    {
        return (string) $this->route('calendar');
    }
}
