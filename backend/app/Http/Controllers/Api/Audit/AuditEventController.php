<?php

namespace App\Http\Controllers\Api\Audit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListAuditEventsRequest;
use App\Http\Resources\Audit\AuditEventResource;
use App\Models\Audit\AuditEvent;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The company's audit history, newest first (audit.read). */
class AuditEventController extends Controller
{
    public function index(ListAuditEventsRequest $request, string $company): AnonymousResourceCollection
    {
        $events = AuditEvent::query()->where('company_id', $company)->newestFirst()
            ->select(['id', 'actor_id', 'action', 'resource_id', 'correlation_id', 'changes', 'reason', 'occurred_at']);

        return AuditEventResource::collection($events->paginate($request->perPage()));
    }
}
