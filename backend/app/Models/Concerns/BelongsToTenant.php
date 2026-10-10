<?php

namespace App\Models\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned rows. Every query is limited to the current tenant context and fails closed (LogicException) outside one,
 * in addition to PostgreSQL RLS. New rows take the context tenant, and saves/deletes of loaded models match it too.
 * Bypass the scope only for deliberate cross-tenant reads.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', fn (Builder $query) => $query->where($query->qualifyColumn('tenant_id'), app(TenantContext::class)->id()));
        static::creating(function (Model $model): void {
            $model->tenant_id ??= app(TenantContext::class)->id();
        });
    }

    /** Updates and deletes of a loaded model also match the context tenant, as the pre-Eloquent writes did. */
    protected function setKeysForSaveQuery($query)
    {
        return parent::setKeysForSaveQuery($query)->where($this->qualifyColumn('tenant_id'), app(TenantContext::class)->id());
    }
}
