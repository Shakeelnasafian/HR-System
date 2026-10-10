<?php

namespace App\Models\Workforce;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRandomUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A person, tenant-wide; companies see them only through employments in that company (scopeInCompany).
 * The employee row is the per-person aggregate lock: employment transitions and profile writes lock it first.
 */
class Employee extends Model
{
    use BelongsToTenant, HasRandomUuid;

    /** Directory fields safe for workforce.read; private profile data lives in EmployeeProfile. */
    public const DIRECTORY = ['id', 'employee_number', 'legal_name', 'preferred_name'];

    protected $fillable = ['employee_number', 'legal_name', 'preferred_name'];

    public function employments(): HasMany
    {
        return $this->hasMany(Employment::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    /** People with any employment (whatever its status) in $company. @param  Builder<self>  $query */
    public function scopeInCompany(Builder $query, string $company): void
    {
        $query->whereHas('employments', fn (Builder $employments) => $employments->where('company_id', $company));
    }
}
