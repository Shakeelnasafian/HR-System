<?php

namespace App\Http\Controllers\Api\Tenancy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\ShowCapabilitiesRequest;
use App\Http\Resources\Tenancy\CompanyCapabilitiesResource;
use App\Models\Tenancy\CompanyGrant;
use App\Services\Tenancy\TenantContext;

/** The permissions the signed-in member holds in a company it may read (drives what the UI offers). */
class CapabilitiesController extends Controller
{
    public function __invoke(ShowCapabilitiesRequest $request, TenantContext $context): CompanyCapabilitiesResource
    {
        $permissions = CompanyGrant::query()->join('tenant_memberships as m', 'm.id', '=', 'company_grants.membership_id')
            ->where('company_grants.company_id', $request->companyId())->where('m.user_id', $context->userId())
            ->where('m.status', 'active')->orderBy('company_grants.permission')->pluck('company_grants.permission');

        return new CompanyCapabilitiesResource($permissions);
    }
}
