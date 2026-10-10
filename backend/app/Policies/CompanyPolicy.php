<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Tenancy\CompanyAccess;
use App\Services\Tenancy\PermissionCatalog;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Company permissions as Gate abilities, checked against the route company id (a string, so nothing tenant-owned is
 * loaded before authorization). The ability is the camel-cased permission code: 'workforce.write' => 'workforceWrite'.
 * Denials must surface as 404 (see App\Http\Requests\CompanyRequest); MFA-unverified holders of privileged permissions
 * get the 403 from CompanyAccess before any company is resolved.
 */
class CompanyPolicy
{
    public function __construct(private readonly CompanyAccess $access) {}

    public static function ability(string $permission): string
    {
        if (! array_key_exists($permission, PermissionCatalog::LABELS)) {
            throw new InvalidArgumentException("Unknown company permission [$permission].");
        }

        return Str::camel(str_replace('.', '_', $permission));
    }

    public function companyRead(User $user, string $company): bool
    {
        return $this->access->allows($company, 'company.read');
    }

    public function companyManage(User $user, string $company): bool
    {
        return $this->access->allows($company, 'company.manage');
    }

    public function organizationRead(User $user, string $company): bool
    {
        return $this->access->allows($company, 'organization.read');
    }

    public function organizationWrite(User $user, string $company): bool
    {
        return $this->access->allows($company, 'organization.write');
    }

    public function workforceRead(User $user, string $company): bool
    {
        return $this->access->allows($company, 'workforce.read');
    }

    public function workforceWrite(User $user, string $company): bool
    {
        return $this->access->allows($company, 'workforce.write');
    }

    public function profileRead(User $user, string $company): bool
    {
        return $this->access->allows($company, 'profile.read');
    }

    public function profileWrite(User $user, string $company): bool
    {
        return $this->access->allows($company, 'profile.write');
    }

    public function auditRead(User $user, string $company): bool
    {
        return $this->access->allows($company, 'audit.read');
    }

    public function accessManage(User $user, string $company): bool
    {
        return $this->access->allows($company, 'access.manage');
    }
}
