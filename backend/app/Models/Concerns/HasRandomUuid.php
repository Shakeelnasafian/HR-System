<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/** UUID primary keys generated as random (version 4) UUIDs, as rows created before Eloquent were. */
trait HasRandomUuid
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }
}
