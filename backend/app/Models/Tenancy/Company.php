<?php

namespace App\Models\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRandomUuid;
use App\Models\Organization\Department;
use App\Models\Organization\EmploymentType;
use App\Models\Organization\Location;
use App\Models\Organization\Position;
use App\Models\Organization\WorkingCalendar;
use App\Models\Workforce\Employment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The employing legal entity. Never query companies directly for a request: resolve them through
 * App\Services\Tenancy\CompanyAccess (or CompanyPolicy), which applies the caller's grants and MFA rules.
 * The timezone defines the company's "today". The code is immutable.
 */
class Company extends Model
{
    use BelongsToTenant, HasRandomUuid;

    protected $fillable = ['name', 'code', 'timezone'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'access_version' => 'integer', 'profile_fields' => 'array', 'profile_fields_version' => 'integer'];
    }

    public function grants(): HasMany
    {
        return $this->hasMany(CompanyGrant::class);
    }

    public function permissionBundles(): HasMany
    {
        return $this->hasMany(PermissionBundle::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function employmentTypes(): HasMany
    {
        return $this->hasMany(EmploymentType::class);
    }

    public function calendars(): HasMany
    {
        return $this->hasMany(WorkingCalendar::class);
    }

    public function employments(): HasMany
    {
        return $this->hasMany(Employment::class);
    }

    /**
     * The enabled private-profile fields, read FOR SHARE: a concurrent field-configuration change waits for the reading
     * request instead of racing it. Profile reads and writes must use this rather than a plain attribute read.
     *
     * @return list<string>
     */
    public static function sharedProfileFields(string $company): array
    {
        return static::query()->whereKey($company)->sharedLock()->value('profile_fields');
    }

    /** The company's local calendar date ("today" for activation, calendars and reporting lines). */
    public function today(): string
    {
        return now($this->timezone)->toDateString();
    }
}
