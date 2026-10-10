<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Names are stored trimmed and stay reserved after archive, regardless of case.
        DB::statement('ALTER TABLE permission_bundles DROP CONSTRAINT permission_bundles_tenant_id_company_id_name_unique');
        DB::statement('CREATE UNIQUE INDEX permission_bundles_company_name_ci_unique ON permission_bundles (tenant_id, company_id, lower(name))');
        DB::statement("ALTER TABLE permission_bundles ADD CONSTRAINT permission_bundles_name_trimmed CHECK (name = btrim(name) AND name <> '')");
        DB::statement("ALTER TABLE permission_bundles ADD CONSTRAINT permission_bundles_permissions_nonempty CHECK (jsonb_typeof(permissions) = 'array' AND jsonb_array_length(permissions) > 0)");
    }
    public function down(): void
    {
        DB::statement('ALTER TABLE permission_bundles DROP CONSTRAINT permission_bundles_permissions_nonempty');
        DB::statement('ALTER TABLE permission_bundles DROP CONSTRAINT permission_bundles_name_trimmed');
        DB::statement('DROP INDEX permission_bundles_company_name_ci_unique');
        DB::statement('ALTER TABLE permission_bundles ADD CONSTRAINT permission_bundles_tenant_id_company_id_name_unique UNIQUE (tenant_id, company_id, name)');
    }
};
