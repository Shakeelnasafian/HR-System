<?php

namespace App\Organization;

use App\Services\Audit\Audit;
use App\Services\Tenancy\ScopesCompany;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Company-owned working calendars: patterns are append-only history, holidays are dated exceptions. No presets exist. */
final class CalendarController
{
    use ScopesCompany;

    private const FIELDS = ['id', 'code', 'name', 'archived', 'version'];

    private const PATTERN = ['effective_from' => 'required|date_format:Y-m-d', 'working_days' => 'required|array|min:1|max:7', 'working_days.*' => 'required|integer|between:1,7|distinct'];

    private function patterns(string $company): Builder
    {
        return $this->rows('calendar_patterns', $company)->select(['id', 'calendar_id', 'effective_from'])->selectRaw('array_to_json(working_days) AS working_days');
    }

    private static function days(object $pattern, bool $id = true): array
    {
        return ($id ? ['id' => $pattern->id] : []) + ['effective_from' => $pattern->effective_from, 'working_days' => json_decode($pattern->working_days, true)];
    }

    /** Sorted, distinct ISO weekdays as a PostgreSQL array literal (validation already rejected duplicates and out-of-range values). */
    private static function literal(array $days): string
    {
        $days = array_map('intval', $days);
        sort($days);

        return '{'.implode(',', $days).'}';
    }

    private function calendar(string $company, string $calendar, bool $lock = false): object
    {
        abort_unless(Str::isUuid($calendar), 404);
        $q = $this->rows('working_calendars', $company)->where('id', $calendar);
        if ($lock) {
            $q->lockForUpdate();
        }
        $row = $q->first();
        abort_unless($row, 404);

        return $row;
    }

    /** Locks the calendar, checks the caller's version and, for pattern/holiday changes, that it is still active. */
    private function mutable(Request $r, string $company, string $calendar, array $rules, bool $content): array
    {
        $this->company($company, 'organization.write');
        $data = $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|max:500'] + $rules);
        $row = $this->calendar($company, $calendar, true);
        abort_unless($row->version === (int) $data['version'], 409, 'This calendar changed. Reload before saving.');
        abort_if($content && $row->archived, 409, 'Archived calendars cannot be changed. Restore it first.');

        return [$row, $data];
    }

    private function bump(object $row, string $company, array $changes = []): void
    {
        $this->rows('working_calendars', $company)->where('id', $row->id)->update($changes + ['version' => $row->version + 1, 'updated_at' => now()]);
    }

    private function insertUnique(string $table, array $values, string $field, string $message): void
    {
        try {
            DB::table($table)->insert($values);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    public function index(Request $r, string $company): array
    {
        $row = $this->company($company, 'organization.read');
        $r->validate(['include_archived' => 'sometimes|boolean']);
        $q = $this->rows('working_calendars', $company)->orderBy('name')->orderBy('id');
        if (! $r->boolean('include_archived')) {
            $q->where('archived', false);
        }
        $calendars = $q->get(self::FIELDS);
        // "Today" is the company's local date, matching employment activation.
        $current = $this->patterns($company)->whereIn('calendar_id', $calendars->pluck('id'))->where('effective_from', '<=', now($row->timezone)->toDateString())
            ->orderBy('calendar_id')->orderByDesc('effective_from')->get()->unique('calendar_id')->keyBy('calendar_id');

        return ['data' => $calendars->map(fn ($c) => (array) $c + ['current_pattern' => isset($current[$c->id]) ? self::days($current[$c->id], false) : null])];
    }

    public function store(Request $r, string $company)
    {
        $this->company($company, 'organization.write');
        $data = $r->validate(['code' => 'required|string|max:30|regex:/^[A-Za-z0-9_-]+$/', 'name' => 'required|string|max:120', 'reason' => 'required|string|max:500'] + self::PATTERN);
        $taken = 'This code is already in use.';
        if ($this->rows('working_calendars', $company)->whereRaw('lower(code) = lower(?)', [$data['code']])->exists()) {
            throw ValidationException::withMessages(['code' => $taken]);
        }
        $id = (string) Str::uuid();
        $scope = ['tenant_id' => $this->tenant(), 'company_id' => $company, 'created_at' => now(), 'updated_at' => now()];
        $this->insertUnique('working_calendars', ['id' => $id, 'code' => $data['code'], 'name' => $data['name']] + $scope, 'code', $taken);
        // The first pattern is part of the same transaction: a calendar never exists without one.
        DB::table('calendar_patterns')->insert(['id' => (string) Str::uuid(), 'calendar_id' => $id, 'effective_from' => $data['effective_from'], 'working_days' => self::literal($data['working_days'])] + $scope);
        Audit::record($company, 'calendar.created', $id, ['code' => $data['code'], 'fields' => ['code', 'name', 'effective_from', 'working_days']], $data['reason']);

        return response()->json(['data' => $this->rows('working_calendars', $company)->where('id', $id)->first(self::FIELDS)], 201);
    }

    public function show(Request $r, string $company, string $calendar): array
    {
        $this->company($company, 'organization.read');
        $r->validate(['year' => 'sometimes|integer|between:1900,2999']);
        $row = $this->calendar($company, $calendar);
        $holidays = $this->rows('calendar_holidays', $company)->where('calendar_id', $row->id)->orderBy('holiday_date')->orderBy('id');
        if ($r->filled('year')) {
            $holidays->whereBetween('holiday_date', [$r->integer('year').'-01-01', $r->integer('year').'-12-31']);
        }

        return ['data' => collect((array) $row)->only(self::FIELDS)->all() + [
            'patterns' => $this->patterns($company)->where('calendar_id', $row->id)->orderByDesc('effective_from')->get()->map(fn ($p) => self::days($p)),
            'holidays' => $holidays->get(['id', 'holiday_date', 'name']),
        ]];
    }

    public function update(Request $r, string $company, string $calendar): array
    {
        [$row,$data] = $this->mutable($r, $company, $calendar, ['name' => 'sometimes|required|string|max:120', 'archived' => 'sometimes|required|boolean'], false);
        if (array_key_exists('archived', $data)) {
            $data['archived'] = (bool) $data['archived'];
        }
        $changes = array_filter(array_intersect_key($data, ['name' => 1, 'archived' => 1]), fn ($value, $key) => $value !== $row->$key, ARRAY_FILTER_USE_BOTH);
        if ($changes) {
            $this->bump($row, $company, $changes);
            Audit::record($company, 'calendar.updated', $row->id, ['code' => $row->code, 'fields' => array_keys($changes)], $data['reason']);
        }

        return ['data' => $this->rows('working_calendars', $company)->where('id', $row->id)->first(self::FIELDS)];
    }

    public function addPattern(Request $r, string $company, string $calendar)
    {
        [$row,$data] = $this->mutable($r, $company, $calendar, self::PATTERN, true);
        $taken = 'A pattern already starts on this date.';
        if ($this->rows('calendar_patterns', $company)->where('calendar_id', $row->id)->where('effective_from', $data['effective_from'])->exists()) {
            throw ValidationException::withMessages(['effective_from' => $taken]);
        }
        $id = (string) Str::uuid();
        $days = self::literal($data['working_days']);
        $this->insertUnique('calendar_patterns', ['id' => $id, 'tenant_id' => $this->tenant(), 'company_id' => $company, 'calendar_id' => $row->id, 'effective_from' => $data['effective_from'], 'working_days' => $days, 'created_at' => now(), 'updated_at' => now()], 'effective_from', $taken);
        $this->bump($row, $company);
        Audit::record($company, 'calendar.pattern_added', $row->id, ['code' => $row->code, 'pattern_id' => $id, 'effective_from' => $data['effective_from'], 'working_days' => $days], $data['reason']);

        return response()->json(['data' => self::days($this->patterns($company)->where('id', $id)->first())], 201);
    }

    public function addHoliday(Request $r, string $company, string $calendar)
    {
        [$row,$data] = $this->mutable($r, $company, $calendar, ['holiday_date' => 'required|date_format:Y-m-d', 'name' => 'required|string|max:120'], true);
        $taken = 'This date is already a holiday in this calendar.';
        if ($this->rows('calendar_holidays', $company)->where('calendar_id', $row->id)->where('holiday_date', $data['holiday_date'])->exists()) {
            throw ValidationException::withMessages(['holiday_date' => $taken]);
        }
        $id = (string) Str::uuid();
        $this->insertUnique('calendar_holidays', ['id' => $id, 'tenant_id' => $this->tenant(), 'company_id' => $company, 'calendar_id' => $row->id, 'holiday_date' => $data['holiday_date'], 'name' => $data['name'], 'created_at' => now(), 'updated_at' => now()], 'holiday_date', $taken);
        $this->bump($row, $company);
        Audit::record($company, 'calendar.holiday_added', $row->id, ['code' => $row->code, 'holiday_id' => $id, 'holiday_date' => $data['holiday_date']], $data['reason']);

        return response()->json(['data' => $this->rows('calendar_holidays', $company)->where('id', $id)->first(['id', 'holiday_date', 'name'])], 201);
    }

    public function removeHoliday(Request $r, string $company, string $calendar, string $holiday): array
    {
        abort_unless(Str::isUuid($holiday), 404);
        [$row,$data] = $this->mutable($r, $company, $calendar, [], true); // archived calendars are frozen: no additions or removals
        $item = $this->rows('calendar_holidays', $company)->where('calendar_id', $row->id)->where('id', $holiday)->first();
        abort_unless($item, 404);
        $this->rows('calendar_holidays', $company)->where('id', $holiday)->delete();
        $this->bump($row, $company);
        Audit::record($company, 'calendar.holiday_removed', $row->id, ['code' => $row->code, 'holiday_id' => $holiday, 'holiday_date' => $item->holiday_date], $data['reason']);

        return ['data' => ['id' => $holiday, 'removed' => true]];
    }
}
