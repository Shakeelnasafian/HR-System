<?php

namespace App\Actions\Organization;

use App\Models\Organization\CalendarPattern;
use App\Models\Tenancy\Company;
use App\Services\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/** Patterns are append-only, effective-dated history: at most one pattern starts on a given date. */
class AddCalendarPattern
{
    public function __construct(private readonly LockCalendar $lock) {}

    public function handle(Company $company, string $calendar, array $data): CalendarPattern
    {
        $row = $this->lock->handle($company, $calendar, $data['version'], true);
        $taken = 'A pattern already starts on this date.';
        if (CalendarPattern::query()->where('company_id', $company->id)->where('calendar_id', $row->id)->where('effective_from', $data['effective_from'])->exists()) {
            throw ValidationException::withMessages(['effective_from' => $taken]);
        }
        $days = CalendarPattern::toArrayLiteral($data['working_days']);
        try {
            $pattern = CalendarPattern::create(['company_id' => $company->id, 'calendar_id' => $row->id, 'effective_from' => $data['effective_from'], 'working_days' => $days]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['effective_from' => $taken]);
        }
        $row->bumpVersion();
        Audit::record($company->id, 'calendar.pattern_added', $row->id, ['code' => $row->code, 'pattern_id' => $pattern->id, 'effective_from' => $data['effective_from'], 'working_days' => $days], $data['reason']);

        // working_days is a smallint[]: read it back as JSON.
        return CalendarPattern::query()->where('company_id', $company->id)->withWorkingDays()->whereKey($pattern->id)->firstOrFail();
    }
}
