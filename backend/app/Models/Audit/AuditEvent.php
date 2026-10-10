<?php

namespace App\Models\Audit;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenancy\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only company audit history (the runtime role may only SELECT and INSERT). Write through
 * App\Services\Audit\Audit::record. occurred_at and seq are assigned by the database; seq is the stable order.
 * changes stays uncast: the API returns the stored jsonb text.
 */
class AuditEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('seq');
    }
}
