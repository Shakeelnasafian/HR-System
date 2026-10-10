<?php

namespace App\Actions\Organization;

use App\Models\Organization\CalendarHoliday;
use App\Models\Tenancy\Company;
use App\Services\Audit\Audit;

/** Archived calendars are frozen: no additions or removals. Audited with the date, never the name. */
class RemoveCalendarHoliday
{
    public function __construct(private readonly LockCalendar $lock) {}

    public function handle(Company $company, string $calendar, string $holiday, array $data): void
    {
        $row = $this->lock->handle($company, $calendar, $data['version'], true);
        $item = CalendarHoliday::query()->where('company_id', $company->id)->where('calendar_id', $row->id)->whereKey($holiday)->first();
        abort_unless($item, 404);
        $item->delete();
        $row->bumpVersion();
        Audit::record($company->id, 'calendar.holiday_removed', $row->id, ['code' => $row->code, 'holiday_id' => $holiday, 'holiday_date' => $item->holiday_date], $data['reason']);
    }
}
