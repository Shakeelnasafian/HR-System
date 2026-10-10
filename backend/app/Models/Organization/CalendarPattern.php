<?php

namespace App\Models\Organization;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRandomUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Working weekdays (ISO 1-7) from effective_from until the next pattern. Append-only (the runtime role cannot update).
 * working_days is a PostgreSQL smallint[]: write it with toArrayLiteral(), read it with scopeWithWorkingDays().
 */
class CalendarPattern extends Model
{
    use BelongsToTenant, HasRandomUuid;

    protected $fillable = ['company_id', 'calendar_id', 'effective_from', 'working_days'];

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(WorkingCalendar::class, 'calendar_id');
    }

    /** Sorted, distinct weekdays as an array literal (callers validate range and duplicates first). */
    public static function toArrayLiteral(array $days): string
    {
        $days = array_map('intval', $days);
        sort($days);

        return '{'.implode(',', $days).'}';
    }

    /** Selects the public columns with working_days as a JSON array string. @param  Builder<self>  $query */
    public function scopeWithWorkingDays(Builder $query): void
    {
        $query->select(['id', 'calendar_id', 'effective_from'])->selectRaw('array_to_json(working_days) AS working_days');
    }

    /** @return list<int> */
    public function workingDays(): array
    {
        return json_decode($this->getRawOriginal('working_days'), true);
    }
}
