<?php

namespace App\Tenancy;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Company resolution shared by company-scoped controllers: unknown, foreign or unauthorized IDs are all 404. */
trait ScopesCompany
{
    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private function company(string $company, string $permission, bool $lock = false): object
    {
        abort_unless(Str::isUuid($company), 404);
        $q = app(CompanyAccess::class)->readable($permission)->where('companies.id', $company);
        if ($lock) {
            $q->lockForUpdate();
        }
        $row = $q->first();
        abort_unless($row, 404);

        return $row;
    }

    private function rows(string $table, string $company): Builder
    {
        return DB::table($table)->where('tenant_id', $this->tenant())->where('company_id', $company);
    }
}
