<?php

namespace App\Models\Organization;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRandomUuid;
use App\Models\Tenancy\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Company-owned reference data with an upper-case code that is unique per company, an archive flag and a version. */
abstract class OrganizationUnit extends Model
{
    use BelongsToTenant, HasRandomUuid;

    /** Route {kind} => model. */
    public const KINDS = [
        'departments' => Department::class,
        'locations' => Location::class,
        'positions' => Position::class,
        'employment_types' => EmploymentType::class,
    ];

    protected $fillable = ['company_id', 'code', 'name', 'archived'];

    protected function casts(): array
    {
        return ['archived' => 'boolean', 'version' => 'integer'];
    }

    /** @return class-string<self>|null */
    public static function forKind(string $kind): ?string
    {
        return self::KINDS[$kind] ?? null;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('archived'), false);
    }
}
