<?php

namespace App\Http\Controllers\Api\Tenancy;

use App\Actions\Tenancy\CancelInvitation;
use App\Actions\Tenancy\CreateInvitation;
use App\Actions\Tenancy\ResendInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\ChangeInvitationRequest;
use App\Http\Requests\Tenancy\ListInvitationsRequest;
use App\Http\Requests\Tenancy\StoreInvitationRequest;
use App\Http\Resources\Tenancy\InvitationResource;
use App\Models\Tenancy\Invitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Company invitations (TEN-03): list, invite, resend, cancel. */
class InvitationController extends Controller
{
    public function index(ListInvitationsRequest $request): AnonymousResourceCollection
    {
        $invitations = Invitation::query()->where('company_id', $request->companyId())->presented();
        if ($request->onlyPending()) {
            // Database clock decides expiry.
            $invitations->where('status', 'pending')->whereRaw('expires_at > clock_timestamp()');
        }

        return InvitationResource::collection($invitations->orderByDesc('created_at')->orderBy('id')->paginate($request->perPage()));
    }

    public function store(StoreInvitationRequest $request, CreateInvitation $create): JsonResponse
    {
        $id = $create->handle($request->company(lock: true), $request->validated());

        return (new InvitationResource($this->show($request->companyId(), $id)))->response()->setStatusCode(201);
    }

    public function resend(ChangeInvitationRequest $request, ResendInvitation $resend): InvitationResource
    {
        $resend->handle($request->company(lock: true), $request->invitationId(), $request->validated());

        return new InvitationResource($this->show($request->companyId(), $request->invitationId()));
    }

    public function cancel(ChangeInvitationRequest $request, CancelInvitation $cancel): InvitationResource
    {
        $cancel->handle($request->company(lock: true), $request->invitationId(), $request->validated());

        return new InvitationResource($this->show($request->companyId(), $request->invitationId()));
    }

    private function show(string $company, string $id): Invitation
    {
        return Invitation::query()->where('company_id', $company)->whereKey($id)->presented()->firstOrFail();
    }
}
