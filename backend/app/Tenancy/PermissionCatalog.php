<?php
namespace App\Tenancy;

final class PermissionCatalog
{
    public const LABELS = [
        'company.read'=>'View company',
        'organization.read'=>'View organization',
        'organization.write'=>'Manage departments, locations and positions',
        'workforce.read'=>'View employee directory and employment history',
        'workforce.write'=>'Create employees and change employment',
        'audit.read'=>'View company audit history',
        'access.manage'=>'Manage company permissions',
    ];

    public static function requiresMfa(array $permissions): bool
    {
        return count(array_diff($permissions,['company.read','organization.read'])) > 0;
    }
}
