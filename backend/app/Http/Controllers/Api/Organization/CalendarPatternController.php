<?php

namespace App\Http\Controllers\Api\Organization;

use App\Actions\Organization\AddCalendarPattern;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\AddCalendarPatternRequest;
use App\Http\Resources\Organization\CalendarPatternResource;
use Illuminate\Http\JsonResponse;

/** Appends to a calendar's effective-dated pattern history (patterns are never edited or removed). */
class CalendarPatternController extends Controller
{
    public function store(AddCalendarPatternRequest $request, AddCalendarPattern $action): JsonResponse
    {
        $pattern = $action->handle($request->company(), $request->calendarId(), $request->validated());

        return (new CalendarPatternResource($pattern))->response()->setStatusCode(201);
    }
}
