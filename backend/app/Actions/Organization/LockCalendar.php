<?php

namespace App\Actions\Organization;

use App\Models\Organization\WorkingCalendar;
use App\Models\Tenancy\Company;
use Illuminate\Support\Str;

/**
 * The first step of every calendar change: lock the calendar row FOR UPDATE (404 when it is not in the company), check
 * the caller's version (409) and, for pattern and holiday changes, that the calendar is not archived (archived calendars
 * are frozen). Callers then write through WorkingCalendar::bumpVersion().
 */
class LockCalendar
{
    public function handle(Company $company, string $calendar, int|string $version, bool $content): WorkingCalendar
    {
        abort_unless(Str::isUuid($calendar), 404);
        $row = WorkingCalendar::query()->where('company_id', $company->id)->whereKey($calendar)->lockForUpdate()->first();
        abort_unless($row, 404);
        abort_unless($row->version === (int) $version, 409, 'This calendar changed. Reload before saving.');
        abort_if($content && $row->archived, 409, 'Archived calendars cannot be changed. Restore it first.');

        return $row;
    }
}
