<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['departments', 'locations', 'positions'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id');
                $t->uuid('company_id');
                $t->string('code', 40);
                $t->string('name', 160);
                $t->boolean('archived')->default(false);
                $t->unsignedInteger('version')->default(1);
                $t->timestampsTz();
                $t->unique(['tenant_id', 'company_id', 'id']);
                $t->unique(['tenant_id', 'company_id', 'code']);
                $t->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies');
            });
        }
        Schema::create('employees', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->string('employee_number', 40);
            $t->string('legal_name', 160);
            $t->string('preferred_name', 160)->nullable();
            $t->timestampsTz();
            $t->foreign('tenant_id')->references('id')->on('tenants');
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'employee_number']);
        });
        Schema::create('employments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('company_id');
            $t->uuid('employee_id');
            $t->string('employment_number', 40);
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->string('status')->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->uuid('department_id')->nullable();
            $t->uuid('location_id')->nullable();
            $t->uuid('position_id')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'company_id', 'id']);
            $t->unique(['tenant_id', 'company_id', 'employment_number']);
            $t->index(['tenant_id', 'employee_id', 'start_date']);
            $t->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies');
            $t->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('employees');
            foreach (['department' => 'departments', 'location' => 'locations', 'position' => 'positions'] as $key => $table) {
                $t->foreign(['tenant_id', 'company_id', $key.'_id'])->references(['tenant_id', 'company_id', 'id'])->on($table);
            }
        });
        DB::statement("ALTER TABLE employments ADD CHECK (status IN ('draft','active','ended','cancelled'))");
        DB::statement("ALTER TABLE employments ADD CHECK ((status = 'ended' AND end_date > start_date) OR (status <> 'ended' AND end_date IS NULL))");
        Schema::create('audit_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('company_id');
            $t->unsignedBigInteger('actor_id');
            $t->string('action', 80);
            $t->uuid('resource_id');
            $t->uuid('correlation_id');
            $t->jsonb('changes');
            $t->text('reason')->nullable();
            $t->timestampTz('occurred_at');
            $t->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies');
            $t->index(['tenant_id', 'company_id', 'occurred_at']);
        });
        foreach (['departments', 'locations', 'positions', 'employees', 'employments', 'audit_events'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_boundary ON $table USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
        }
    }

    public function down(): void
    {
        foreach (['audit_events', 'employments', 'employees', 'positions', 'locations', 'departments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
