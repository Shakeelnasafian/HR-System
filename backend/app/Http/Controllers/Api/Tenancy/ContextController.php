<?php

namespace App\Http\Controllers\Api\Tenancy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\ListCompaniesRequest;
use App\Http\Resources\Tenancy\CompanyListItemResource;
use App\Http\Resources\Tenancy\CompanySummaryResource;
use App\Services\Tenancy\CompanyAccess;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The selected tenant and the companies the principal may read in it. */
class ContextController extends Controller
{
    public function show(CompanyAccess $access, TenantContext $context): array
    {
        return ['data' => [
            'tenant_id' => $context->id(),
            'companies' => CompanySummaryResource::collection($access->query()->orderBy('name')->get(['id', 'name', 'code'])),
        ]];
    }

    public function companies(ListCompaniesRequest $request, CompanyAccess $access): AnonymousResourceCollection
    {
        return CompanyListItemResource::collection($access->query()->orderBy('name')->orderBy('id')->paginate($request->perPage(), ['id', 'name', 'code', 'timezone']));
    }
}
