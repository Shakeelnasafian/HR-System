<?php

namespace App\Actions\Tenancy;

use App\Models\Tenancy\Company;
use App\Models\Tenancy\CompanyGrant;
use App\Models\Tenancy\TenantMembership;
use App\Services\Tenancy\TenantContext;

/**
 * The acting membership and the permissions it currently holds in a company. Mutations call this after locking the
 * company row ($request->company(lock: true)), so the grants are read as of their own transaction; access.manage is
 * rechecked here and a grant removed while the request waited for the lock is a 404.
 */
final class ManagerAuthority
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array{0: TenantMembership, 1: list<string>} actor membership, actor's current permissions in the company */
    public function of(Company $company): array
    {
        // access.manage is privileged, so resolving the company already required an MFA-verified session.
        $actor = TenantMembership::query()->active()->where('user_id', $this->context->userId())->firstOr(fn () => abort(404, 'Not found.'));
        $held = CompanyGrant::query()->for($company->id, $actor->id)->pluck('permission')->all();
        // Recheck after the company lock, shared by every grant, bundle and invitation mutation.
        abort_unless(in_array('access.manage', $held, true), 404);

        return [$actor, $held];
    }
}
