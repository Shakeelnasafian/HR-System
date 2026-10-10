<?php

namespace App\Models\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRandomUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company invitation. The email lives only here; token_hash is the SHA-256 of the emailed secret, written only by the
 * invitation.send outbox handler, and never serialized. Acceptance goes through the hr_accept_invitation definer function.
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
}
