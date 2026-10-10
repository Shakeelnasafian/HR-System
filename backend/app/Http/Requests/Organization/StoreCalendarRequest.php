<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\CompanyRequest;

class StoreCalendarRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'organization.write';
    }

    public function rules(): array
    {
        return ['code' => 'required|string|max:30|regex:/^[A-Za-z0-9_-]+$/', 'name' => 'required|string|max:120', 'reason' => 'required|string|max:500'] + AddCalendarPatternRequest::PATTERN;
    }
}
