<?php

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\Account\CurrentUserResource;
use App\Http\Resources\Account\TenantMembershipSummaryResource;
use App\Models\Tenancy\TenantMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in account. Outside any tenant context, so nothing here may expose tenant data beyond membership names. */
class CurrentUserController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return (new CurrentUserResource($request->user()))->response()->header('Cache-Control', 'no-store, private');
    }

    /** Active memberships in active tenants: the tenant selector before a tenant context exists. */
    public function tenants(Request $request): JsonResponse
    {
        // Deliberately cross-tenant: one row per tenant the user belongs to, read before any tenant context is set.
        $memberships = TenantMembership::withoutGlobalScope('tenant')->active()->where('tenant_memberships.user_id', $request->user()->id)
            ->join('tenants', 'tenants.id', '=', 'tenant_memberships.tenant_id')->where('tenants.status', 'active')
            ->orderBy('tenants.name')->get(['tenants.id', 'tenants.name', 'tenant_memberships.requires_mfa']);

        return TenantMembershipSummaryResource::collection($memberships)->response()->header('Cache-Control', 'no-store, private');
    }
}
