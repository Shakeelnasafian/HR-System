<?php

namespace App\Actions\Organization;

use App\Models\Organization\CalendarHoliday;
use App\Models\Tenancy\Company;
use App\Services\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/** At most one holiday per calendar and date. The holiday name never enters the audit record. */
class AddCalendarHoliday
{
    public function __construct(private readonly LockCalendar $lock) {}

    public function handle(Company $company, string $calendar, array $data): CalendarHoliday
    {
        $row = $this->lock->handle($company, $calendar, $data['version'], true);
        $taken = 'This date is already a holiday in this calendar.';
        if (CalendarHoliday::query()->where('company_id', $company->id)->where('calendar_id', $row->id)->where('holiday_date', $data['holiday_date'])->exists()) {
            throw ValidationException::withMessages(['holiday_date' => $taken]);
        }
        try {
            $holiday = CalendarHoliday::create(['company_id' => $company->id, 'calendar_id' => $row->id, 'holiday_date' => $data['holiday_date'], 'name' => $data['name']]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['holiday_date' => $taken]);
        }
        $row->bumpVersion();
        Audit::record($company->id, 'calendar.holiday_added', $row->id, ['code' => $row->code, 'holiday_id' => $holiday->id, 'holiday_date' => $data['holiday_date']], $data['reason']);

        return CalendarHoliday::query()->where('company_id', $company->id)->whereKey($holiday->id)->firstOrFail();
    }
}
