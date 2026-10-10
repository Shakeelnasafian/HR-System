<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Tenant isolation requires PostgreSQL.');
        }
        Schema::table('users', function (Blueprint $t) {
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestampTz('two_factor_confirmed_at')->nullable();
        });
        Schema::create('tenants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('status')->default('active');
            $t->timestampsTz();
        });
        Schema::create('tenant_memberships', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->foreignId('user_id')->constrained();
            $t->string('status')->default('active');
            $t->boolean('requires_mfa')->default(true);
            $t->timestampsTz();
            $t->foreign('tenant_id')->references('id')->on('tenants');
            $t->unique(['tenant_id', 'user_id']);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('companies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->string('name');
            $t->string('code');
            $t->string('timezone')->default('Asia/Dubai');
            $t->timestampsTz();
            $t->foreign('tenant_id')->references('id')->on('tenants');
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'code']);
        });
        Schema::create('company_grants', function (Blueprint $t) {
            $t->id();
            $t->uuid('tenant_id');
            $t->uuid('membership_id');
            $t->uuid('company_id');
            $t->string('permission');
            $t->foreign(['tenant_id', 'membership_id'])->references(['tenant_id', 'id'])->on('tenant_memberships');
            $t->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies');
            $t->unique(['tenant_id', 'membership_id', 'company_id', 'permission']);
        });
        foreach (['tenants', 'tenant_memberships'] as $table) {
            DB::statement("ALTER TABLE $table ADD CHECK (status IN ('active', 'suspended', 'archived'))");
        }
        foreach (['companies', 'company_grants'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_boundary ON $table USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_grants');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('tenants');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']));
    }
};
