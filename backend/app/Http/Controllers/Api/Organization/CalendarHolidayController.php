<?php

namespace App\Http\Controllers\Api\Organization;

use App\Actions\Organization\AddCalendarHoliday;
use App\Actions\Organization\RemoveCalendarHoliday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\AddCalendarHolidayRequest;
use App\Http\Requests\Organization\RemoveCalendarHolidayRequest;
use App\Http\Resources\Organization\CalendarHolidayResource;
use Illuminate\Http\JsonResponse;

/** Dated non-working exceptions in one calendar. */
class CalendarHolidayController extends Controller
{
    public function store(AddCalendarHolidayRequest $request, AddCalendarHoliday $action): JsonResponse
    {
        $holiday = $action->handle($request->company(), $request->calendarId(), $request->validated());

        return (new CalendarHolidayResource($holiday))->response()->setStatusCode(201);
    }

    public function destroy(RemoveCalendarHolidayRequest $request, RemoveCalendarHoliday $action): array
    {
        $action->handle($request->company(), $request->calendarId(), $request->holidayId(), $request->validated());

        return ['data' => ['id' => $request->holidayId(), 'removed' => true]];
    }
}
