<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\CompanyRequest;

/** The calendar is resolved after validation, so an invalid year on an unknown calendar is a 422. */
class ShowCalendarRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'organization.read';
    }

    public function rules(): array
    {
        return ['year' => 'sometimes|integer|between:1900,2999'];
    }

    public function calendarId(): string
    {
        return (string) $this->route('calendar');
    }
}
