<?php

namespace App\Http\Controllers\Api\Tenancy;

use App\Actions\Tenancy\ArchivePermissionBundle;
use App\Actions\Tenancy\CreatePermissionBundle;
use App\Actions\Tenancy\ManagerAuthority;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\ArchivePermissionBundleRequest;
use App\Http\Requests\Tenancy\ListPermissionBundlesRequest;
use App\Http\Requests\Tenancy\StorePermissionBundleRequest;
use App\Http\Resources\Tenancy\ArchivedBundleResource;
use App\Http\Resources\Tenancy\DelegableBundleResource;
use App\Http\Resources\Tenancy\PermissionBundleResource;
use App\Models\Tenancy\PermissionBundle;
use Illuminate\Http\JsonResponse;

/** Immutable permission templates of a company: list current ones, create, archive. */
class PermissionBundleController extends Controller
{
    public function index(ListPermissionBundlesRequest $request, ManagerAuthority $authority): array
    {
        $company = $request->company();
        [, $held] = $authority->of($company);
        $bundles = PermissionBundle::query()->where('company_id', $company->id)->where('archived', false)->orderBy('name')->get(['id', 'name', 'permissions']);

        return ['data' => $bundles->map(fn (PermissionBundle $bundle) => new DelegableBundleResource($bundle, $held))->all()];
    }

    public function store(StorePermissionBundleRequest $request, CreatePermissionBundle $create): JsonResponse
    {
        $bundle = $create->handle($request->company(lock: true), $request->validated());

        return (new PermissionBundleResource($bundle))->response()->setStatusCode(201);
    }

    public function archive(ArchivePermissionBundleRequest $request, ArchivePermissionBundle $archive): ArchivedBundleResource
    {
        $bundle = $archive->handle($request->company(lock: true), $request->bundleId(), $request->validated('reason'));

        return new ArchivedBundleResource(['id' => $request->bundleId(), 'archived' => $bundle->archived]);
    }
}
