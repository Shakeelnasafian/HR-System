<?php

namespace App\Http\Controllers\Api\Tenancy;

use App\Actions\Tenancy\ManagerAuthority;
use App\Actions\Tenancy\ReplaceCompanyAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\ListCompanyAccessRequest;
use App\Http\Requests\Tenancy\ReplaceCompanyAccessRequest;
use App\Http\Resources\Tenancy\CompanyMemberResource;
use App\Http\Resources\Tenancy\MembershipAccessResource;
use App\Models\Tenancy\TenantMembership;
use App\Services\Tenancy\PermissionCatalog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Who has access to a company and with which permissions; replacing one member's permissions there. */
class CompanyAccessController extends Controller
{
    public function index(ListCompanyAccessRequest $request, ManagerAuthority $authority): AnonymousResourceCollection
    {
        $company = $request->company();
        [$actor, $held] = $authority->of($company);
        $members = TenantMembership::query()->withAccessTo($company->id)->join('users as u', 'u.id', '=', 'tenant_memberships.user_id')
            ->with(['grants' => fn ($grants) => $grants->where('company_id', $company->id)->orderBy('permission')->select(['membership_id', 'permission'])])
            ->orderBy('u.name')->orderBy('tenant_memberships.id')
            ->paginate($request->perPage(), ['tenant_memberships.id', 'tenant_memberships.status', 'tenant_memberships.requires_mfa', 'u.name', 'u.email']);
        $catalog = [];
        foreach (PermissionCatalog::LABELS as $permission => $label) {
            $catalog[] = ['permission' => $permission, 'label' => $label, 'delegable' => in_array($permission, $held, true)];
        }

        return CompanyMemberResource::collection($members)->additional(['access_version' => $company->access_version, 'actor_membership_id' => $actor->id, 'catalog' => $catalog]);
    }

    public function update(ReplaceCompanyAccessRequest $request, ReplaceCompanyAccess $replace): MembershipAccessResource
    {
        return new MembershipAccessResource($replace->handle($request->company(lock: true), $request->membershipId(), $request->validated()));
    }
}
