<?php

namespace App\Http\Controllers\Api\Organization;

use App\Actions\Organization\CreateCalendar;
use App\Actions\Organization\UpdateCalendar;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\ListCalendarsRequest;
use App\Http\Requests\Organization\ShowCalendarRequest;
use App\Http\Requests\Organization\StoreCalendarRequest;
use App\Http\Requests\Organization\UpdateCalendarRequest;
use App\Http\Resources\Organization\CalendarDetailResource;
use App\Http\Resources\Organization\CalendarListItemResource;
use App\Http\Resources\Organization\CalendarResource;
use App\Models\Organization\CalendarPattern;
use App\Models\Organization\WorkingCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/** Company-owned working calendars. Patterns and holidays have their own controllers. No presets exist. */
class CalendarController extends Controller
{
    public function index(ListCalendarsRequest $request): AnonymousResourceCollection
    {
        $company = $request->company();
        $query = WorkingCalendar::query()->where('company_id', $company->id)->orderBy('name')->orderBy('id');
        if (! $request->boolean('include_archived')) {
            $query->where('archived', false);
        }
        $calendars = $query->get(['id', 'code', 'name', 'archived', 'version']);
        // "Today" is the company's local date, matching employment activation.
        $current = CalendarPattern::query()->where('company_id', $company->id)->withWorkingDays()->whereIn('calendar_id', $calendars->pluck('id'))
            ->where('effective_from', '<=', $company->today())->orderBy('calendar_id')->orderByDesc('effective_from')->get()
            ->unique('calendar_id')->keyBy('calendar_id');
        $calendars->each(fn (WorkingCalendar $calendar) => $calendar->setRelation('current_pattern', $current[$calendar->id] ?? null));

        return CalendarListItemResource::collection($calendars);
    }

    public function store(StoreCalendarRequest $request, CreateCalendar $action): JsonResponse
    {
        return (new CalendarResource($action->handle($request->company(), $request->validated())))->response()->setStatusCode(201);
    }

    public function show(ShowCalendarRequest $request): CalendarDetailResource
    {
        $company = $request->company();
        abort_unless(Str::isUuid($request->calendarId()), 404);
        $calendar = WorkingCalendar::query()->where('company_id', $company->id)->whereKey($request->calendarId())->first();
        abort_unless($calendar, 404);
        $holidays = $calendar->holidays()->where('company_id', $company->id)->orderBy('holiday_date')->orderBy('id');
        if ($request->filled('year')) {
            $holidays->whereBetween('holiday_date', [$request->integer('year').'-01-01', $request->integer('year').'-12-31']);
        }
        $calendar->setRelation('patterns', $calendar->patterns()->where('company_id', $company->id)->withWorkingDays()->orderByDesc('effective_from')->get());
        $calendar->setRelation('holidays', $holidays->get(['id', 'holiday_date', 'name']));

        return new CalendarDetailResource($calendar);
    }

    public function update(UpdateCalendarRequest $request, UpdateCalendar $action): CalendarResource
    {
        return new CalendarResource($action->handle($request->company(), $request->calendarId(), $request->validated()));
    }
}
