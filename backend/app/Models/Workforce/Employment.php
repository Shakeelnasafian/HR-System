<?php

namespace App\Models\Workforce;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRandomUuid;
use App\Models\Tenancy\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employment relationship with a company: draft -> active -> ended, or draft -> cancelled.
 * Dates are half-open: start_date inclusive, end_date exclusive. Organizational placement lives in effective-dated
 * EmploymentAssignment rows, never on the employment.
 */
class Employment extends Model
{
    use BelongsToTenant, HasRandomUuid;

    /** Columns exposed by employment endpoints. */
    public const FIELDS = ['id', 'employment_number', 'start_date', 'end_date', 'status', 'version', 'probation_end_date'];

    protected $fillable = ['company_id', 'employee_id', 'employment_number', 'start_date', 'probation_end_date'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmploymentAssignment::class);
    }

    /** Not cancelled and covering $date. @param  Builder<self>  $query */
    public function scopeCovering(Builder $query, string $date): void
    {
        $query->where('status', '<>', 'cancelled')->where('start_date', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>', $date));
    }
}
