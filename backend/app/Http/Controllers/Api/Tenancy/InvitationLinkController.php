<?php

namespace App\Http\Controllers\Api\Tenancy;

use App\Actions\Tenancy\AcceptInvitation;
use App\Actions\Tenancy\FindInvitationLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\InvitationLinkRequest;
use App\Http\Resources\Tenancy\AcceptedInvitationResource;
use App\Http\Resources\Tenancy\InvitationPreviewResource;
use Illuminate\Http\JsonResponse;

/**
 * Unauthenticated invitation endpoints (SPA session + CSRF, rate limited by IP), outside the tenant middleware.
 * Every unusable invitation, wrong token or malformed selector yields the same 404.
 */
class InvitationLinkController extends Controller
{
    public function preview(InvitationLinkRequest $request, FindInvitationLink $find): JsonResponse
    {
        return (new InvitationPreviewResource($find->handle($request->key())))->response()->header('Cache-Control', 'no-store, private');
    }

    public function accept(InvitationLinkRequest $request, AcceptInvitation $accept): JsonResponse
    {
        $key = $request->key();
        $result = $accept->handle($key, $request->user(), $request->all());

        return (new AcceptedInvitationResource(['tenant_id' => $key[0], 'company_id' => $result->company_id, 'requires_mfa' => (bool) $result->requires_mfa]))
            ->response()->header('Cache-Control', 'no-store, private');
    }
}
