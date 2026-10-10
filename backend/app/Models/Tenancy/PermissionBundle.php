<?php

namespace App\Models\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRandomUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An immutable permission template. Copying a bundle never creates a live grant; names stay reserved after archive. */
class PermissionBundle extends Model
{
    use BelongsToTenant, HasRandomUuid;

    protected $fillable = ['company_id', 'name', 'permissions'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'archived' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
