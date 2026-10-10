<?php

namespace App\Models\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One permission code held by a membership in one company. Privileged grants require an MFA membership (database trigger). */
class CompanyGrant extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['company_id', 'membership_id', 'permission'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeFor(Builder $query, string $company, string $membership): void
    {
        $query->where('company_id', $company)->where('membership_id', $membership);
    }
}
