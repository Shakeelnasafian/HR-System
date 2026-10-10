<?php

namespace App\Http\Controllers\Api\Tenancy;

use App\Actions\Tenancy\RevokeMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\RevokeMembershipRequest;
use App\Http\Resources\Tenancy\RevokedMembershipResource;

/** Removes a member from the whole tenant (all companies), from one company's access screen. */
class RevokeMembershipController extends Controller
{
    public function __invoke(RevokeMembershipRequest $request, RevokeMembership $revoke): RevokedMembershipResource
    {
        return new RevokedMembershipResource($revoke->handle($request->company(lock: true), $request->membershipId(), $request->validated()));
    }
}
