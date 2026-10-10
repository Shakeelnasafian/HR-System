<?php

namespace App\Models\Organization;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRandomUuid;
use App\Models\Tenancy\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company-owned working calendar. Patterns are append-only, effective-dated history and holidays are dated exceptions.
 * Pattern and holiday changes lock the calendar row and bump its version; archived calendars are frozen. No presets exist.
 */
class WorkingCalendar extends Model
{
    use BelongsToTenant, HasRandomUuid;

    protected $fillable = ['company_id', 'code', 'name', 'archived'];

    protected function casts(): array
    {
        return ['archived' => 'boolean', 'version' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function patterns(): HasMany
    {
        return $this->hasMany(CalendarPattern::class, 'calendar_id');
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(CalendarHoliday::class, 'calendar_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('archived'), false);
    }

    /** Saves $changes with the next version: every accepted calendar, pattern or holiday change bumps it (callers hold the row lock). */
    public function bumpVersion(array $changes = []): void
    {
        $this->forceFill($changes + ['version' => $this->version + 1])->save();
    }
}
