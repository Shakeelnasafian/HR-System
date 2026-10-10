<?php

namespace App\Http\Controllers\Api\Organization;

use App\Actions\Organization\CreateOrganizationUnit;
use App\Actions\Organization\UpdateOrganizationUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\ListOrganizationUnitsRequest;
use App\Http\Requests\Organization\StoreOrganizationUnitRequest;
use App\Http\Requests\Organization\UpdateOrganizationUnitRequest;
use App\Http\Resources\Organization\OrganizationUnitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Departments, locations, positions and employment types (/organization/{kind}). */
class OrganizationUnitController extends Controller
{
    public function index(ListOrganizationUnitsRequest $request): AnonymousResourceCollection
    {
        $model = $request->unitModel();

        return OrganizationUnitResource::collection($model::query()->where('company_id', $request->company()->id)
            ->orderBy('name')->orderBy('id')->paginate($request->perPage(), ['id', 'code', 'name', 'archived', 'version']));
    }

    public function store(StoreOrganizationUnitRequest $request, CreateOrganizationUnit $action): JsonResponse
    {
        $unit = $action->handle($request->company(), $request->kind(), $request->unitModel(), $request->validated());

        return (new OrganizationUnitResource($unit))->response()->setStatusCode(201);
    }

    public function update(UpdateOrganizationUnitRequest $request, UpdateOrganizationUnit $action): OrganizationUnitResource
    {
        return new OrganizationUnitResource($action->handle($request->company(), $request->kind(), $request->unitModel(), $request->unitId(), $request->validated()));
    }
}
