<?php

namespace App\Http\Resources\Organization;

use App\Models\Organization\CalendarPattern;
use App\Models\Organization\WorkingCalendar;
use Illuminate\Http\Request;

/**
 * A calendar in the listing with the pattern in effect on the company's local date (null when every pattern starts later).
 * The caller sets the current_pattern relation (a CalendarPattern selected withWorkingDays(), or null).
 *
 * @mixin WorkingCalendar
 */
class CalendarListItemResource extends CalendarResource
{
    public function toArray(Request $request): array
    {
        /** @var CalendarPattern|null $current */
        $current = $this->resource->getRelation('current_pattern');

        return parent::toArray($request) + [
            'current_pattern' => $current ? ['effective_from' => $current->effective_from, 'working_days' => $current->workingDays()] : null,
        ];
    }
}
