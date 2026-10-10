<?php

namespace App\Models\Tenancy;

use App\Models\Concerns\HasRandomUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An organization (customer). Read-only for the runtime role; tenants are provisioned by operators. */
class Tenant extends Model
{
    use HasRandomUuid;

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }
}
