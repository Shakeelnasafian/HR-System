<?php

namespace App\Http\Resources\Audit;

use App\Models\Audit\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One audit record. changes is the stored jsonb text and occurred_at the stored timestamp, both unparsed. @mixin AuditEvent */
class AuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'actor_id' => $this->actor_id, 'action' => $this->action, 'resource_id' => $this->resource_id, 'correlation_id' => $this->correlation_id,
            'changes' => $this->resource->getRawOriginal('changes'), 'reason' => $this->reason, 'occurred_at' => $this->resource->getRawOriginal('occurred_at')];
    }
}
