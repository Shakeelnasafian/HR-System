<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('permission_bundles', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('tenant_id'); $t->uuid('company_id');
            $t->string('name', 100); $t->jsonb('permissions');
            $t->boolean('archived')->default(false); $t->timestampsTz();
            $t->foreign(['tenant_id','company_id'])->references(['tenant_id','id'])->on('companies');
            $t->unique(['tenant_id','company_id','name']);
        });
        DB::statement('ALTER TABLE permission_bundles ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE permission_bundles FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_boundary ON permission_bundles USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
    }
    public function down(): void { Schema::dropIfExists('permission_bundles'); }
};
