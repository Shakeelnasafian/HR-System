<?php

namespace App\Models\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRandomUuid;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A company invitation. The email lives only here; token_hash is the SHA-256 of the emailed secret, written only by the
 * invitation.send outbox handler, and never serialized. Acceptance goes through the hr_accept_invitation definer function.
 * Expiry is always decided by the database clock.
 */
class Invitation extends Model
{
    use BelongsToTenant, HasRandomUuid;

    protected $fillable = ['company_id', 'email', 'permissions', 'requires_mfa', 'expires_at', 'invited_by'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'requires_mfa' => 'boolean', 'send' => 'integer', 'version' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** expires_at for a new or resent invitation: the configured lifetime from the database clock. */
    public static function expiry(): Expression
    {
        return DB::raw('clock_timestamp() + make_interval(hours => '.(int) config('invitations.ttl_hours').')');
    }

    /**
     * The columns of the API representation. A stored pending row past expires_at is reported as expired.
     *
     * @param  Builder<self>  $query
     */
    public function scopePresented(Builder $query): void
    {
        $query->select(['id', 'email', 'permissions', 'requires_mfa', DB::raw("CASE WHEN status = 'pending' AND expires_at <= clock_timestamp() THEN 'expired' ELSE status END AS status"),
            'expires_at', 'created_at', 'version']);
    }
}
