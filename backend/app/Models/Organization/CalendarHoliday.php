<?php

namespace App\Models\Organization;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRandomUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A dated non-working exception in one calendar; unique per calendar and date. */
class CalendarHoliday extends Model
{
    use BelongsToTenant, HasRandomUuid;

    protected $fillable = ['company_id', 'calendar_id', 'holiday_date', 'name'];

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(WorkingCalendar::class, 'calendar_id');
    }
}
