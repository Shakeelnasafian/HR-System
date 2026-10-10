<?php

namespace App\Actions\Organization;

use App\Models\Organization\CalendarPattern;
use App\Models\Organization\WorkingCalendar;
use App\Models\Tenancy\Company;
use App\Services\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/** A calendar is created with its first pattern in the same transaction: a calendar never exists without one. */
class CreateCalendar
{
    public function handle(Company $company, array $data): WorkingCalendar
    {
        $taken = 'This code is already in use.';
        if (WorkingCalendar::query()->where('company_id', $company->id)->whereRaw('lower(code) = lower(?)', [$data['code']])->exists()) {
            throw ValidationException::withMessages(['code' => $taken]);
        }
        try {
            $calendar = WorkingCalendar::create(['company_id' => $company->id, 'code' => $data['code'], 'name' => $data['name']]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => $taken]);
        }
        CalendarPattern::create(['company_id' => $company->id, 'calendar_id' => $calendar->id, 'effective_from' => $data['effective_from'], 'working_days' => CalendarPattern::toArrayLiteral($data['working_days'])]);
        Audit::record($company->id, 'calendar.created', $calendar->id, ['code' => $data['code'], 'fields' => ['code', 'name', 'effective_from', 'working_days']], $data['reason']);

        // Re-read for the database defaults (archived, version).
        return WorkingCalendar::query()->where('company_id', $company->id)->whereKey($calendar->id)->firstOrFail();
    }
}
