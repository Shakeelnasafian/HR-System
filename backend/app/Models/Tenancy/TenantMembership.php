<?php

namespace App\Models\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRandomUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's membership in one tenant. The runtime role may only read memberships; they change through SECURITY DEFINER
 * functions (invitation acceptance, membership revocation). The tenant bootstrap in TenantContext reads them before any
 * tenant context exists and therefore stays on the query builder.
 */
class TenantMembership extends Model
{
    use BelongsToTenant, HasRandomUuid;

    protected function casts(): array
    {
        return ['requires_mfa' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(CompanyGrant::class, 'membership_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), 'active');
    }
}
