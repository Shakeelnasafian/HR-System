<?php

namespace App\Http\Resources\Tenancy;

use App\Models\Tenancy\PermissionBundle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bundle in the picker, with whether the actor may apply it (holds every permission in it).
 *
 * @mixin PermissionBundle
 */
class DelegableBundleResource extends JsonResource
{
    /** @param  list<string>  $held  the actor's current permissions in the company */
    public function __construct(PermissionBundle $resource, private readonly array $held)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'permissions' => $this->permissions, 'delegable' => ! array_diff($this->permissions, $this->held)];
    }
}
