<?php

namespace App\Models\Workforce;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRandomUuid;
use App\Models\Organization\Department;
use App\Models\Organization\EmploymentType;
use App\Models\Organization\Location;
use App\Models\Organization\Position;
use App\Models\Organization\WorkingCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Effective-dated placement of an employment (append-only; one row per employment and date). A row applies from
 * effective_from until the employment's next row. Reporting lines link employments; cycles are checked between people.
 */
class EmploymentAssignment extends Model
{
    use BelongsToTenant, HasRandomUuid;

    const UPDATED_AT = null;

    /** Reference key => referenced model (same company, active when assigned). */
    public const REFERENCES = [
        'department_id' => Department::class,
        'location_id' => Location::class,
        'position_id' => Position::class,
        'employment_type_id' => EmploymentType::class,
        'calendar_id' => WorkingCalendar::class,
    ];

    /** Every assignable key, including the reporting line. */
    public const KEYS = ['department_id', 'location_id', 'position_id', 'employment_type_id', 'calendar_id', 'manager_employment_id'];

    protected $fillable = ['company_id', 'employment_id', 'effective_from', 'created_by', 'reason', ...self::KEYS];

    public function employment(): BelongsTo
    {
        return $this->belongsTo(Employment::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employment::class, 'manager_employment_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(WorkingCalendar::class);
    }

    /** The newest rows first, the order in which "in effect on" is decided. @param  Builder<self>  $query */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('effective_from');
    }

    /** Rows that have started by $date. @param  Builder<self>  $query */
    public function scopeEffectiveOn(Builder $query, string $date): void
    {
        $query->where('effective_from', '<=', $date);
    }
}
