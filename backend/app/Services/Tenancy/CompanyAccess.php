<?php

namespace App\Services\Tenancy;

use App\Models\Tenancy\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The companies the current principal may use with a permission. Every company-scoped endpoint resolves its route
 * company here (through CompanyPolicy, then find() inside the action when it needs the row or a lock), so unknown,
 * foreign and unauthorized companies are indistinguishable 404s.
 */
final class CompanyAccess
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return Builder<Company> */
    public function query(string $permission = 'company.read'): Builder
    {
        // Privileged permissions are usable only from an MFA-verified session, whatever the membership flag says.
        // Holders get 403 before any resource is resolved (no per-company signal); non-holders keep the normal 404 path.
        // Sessionless contexts (jobs, console) are never verified, so they fail closed here.
        if (PermissionCatalog::requiresMfa([$permission]) && ! $this->context->mfaVerified()) {
            abort_if($this->grants(DB::query(), $permission)->exists(), 403, 'MFA login required.');
        }

        return Company::query()->whereExists(fn (QueryBuilder $q) => $this->grants($q->selectRaw('1'), $permission)
            ->whereColumn('g.company_id', 'companies.id')->whereColumn('g.tenant_id', 'companies.tenant_id'));
    }

    /**
     * The route company when the principal holds $permission there, else 404. With $lock the row is locked FOR UPDATE and
     * the grant is re-evaluated in the same statement, so a mutation sees the permission as of its own transaction.
     */
    public function find(string $company, string $permission, bool $lock = false): Company
    {
        abort_unless(Str::isUuid($company), 404);
        $query = $this->query($permission)->whereKey($company);

        return ($lock ? $query->lockForUpdate() : $query)->firstOr(fn () => abort(404));
    }

    /** Whether the principal holds $permission in $company (no MFA abort for non-holders; see query()). */
    public function allows(string $company, string $permission): bool
    {
        return Str::isUuid($company) && $this->query($permission)->whereKey($company)->exists();
    }

    /**
     * Query builder form of query() for callers that still select raw rows.
     *
     * @deprecated Use query() or find(); removed once every module uses models.
     */
    public function readable(string $permission = 'company.read'): QueryBuilder
    {
        return $this->query($permission)->toBase();
    }

    private function grants(QueryBuilder $q, string $permission): QueryBuilder
    {
        return $q->from('company_grants as g')->join('tenant_memberships as m', 'm.id', '=', 'g.membership_id')
            ->where('g.tenant_id', $this->context->id())->where('m.user_id', $this->context->userId())->where('m.status', 'active')
            ->where('g.permission', $permission);
    }
}
