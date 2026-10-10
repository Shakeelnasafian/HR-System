<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// I5a: employment types, configurable private profile, effective-dated assignments (department/location/position/type/calendar/manager).
// Employments' department/location/position move into an initial assignment at start_date; the legacy columns are dropped.
return new class extends Migration
{
    private const TABLES = ['employment_types', 'employee_profiles', 'employment_assignments'];

    private const POLICY = "CREATE POLICY tenant_boundary ON %s USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)";

    public function up(): void
    {
        DB::transaction(function () {
            // Nothing is collected until a company explicitly enables fields (EMP-01); disabling hides values without deleting them.
            Schema::table('companies', function (Blueprint $t) {
                $t->jsonb('profile_fields')->default(DB::raw("'[]'::jsonb"));
                $t->unsignedInteger('profile_fields_version')->default(1);
            });
            DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_profile_fields_valid CHECK (jsonb_typeof(profile_fields) = 'array' AND profile_fields_version >= 1)");
            Schema::create('employment_types', function (Blueprint $t) {
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
            // Tenant-level like employees: the person's private data is shared by all of their company relationships.
            Schema::create('employee_profiles', function (Blueprint $t) {
                $t->uuid('tenant_id');
                $t->uuid('employee_id');
                $t->primary(['tenant_id', 'employee_id']);
                $t->date('birth_date')->nullable();
                $t->string('nationality', 100)->nullable();
                $t->string('personal_email', 254)->nullable();
                $t->string('personal_phone', 50)->nullable();
                $t->text('address')->nullable();
                $t->jsonb('emergency_contacts')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestampsTz();
                $t->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('employees');
            });
            DB::statement("ALTER TABLE employee_profiles ADD CONSTRAINT employee_profiles_valid CHECK (version >= 1 AND (address IS NULL OR char_length(address) <= 1000)
                AND (emergency_contacts IS NULL OR (jsonb_typeof(emergency_contacts) = 'array' AND jsonb_array_length(emergency_contacts) <= 5)))");
            Schema::table('employments', fn (Blueprint $t) => $t->date('probation_end_date')->nullable());
            DB::statement('ALTER TABLE employments ADD CONSTRAINT employments_probation_after_start CHECK (probation_end_date IS NULL OR probation_end_date >= start_date)');
            Schema::create('employment_assignments', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id');
                $t->uuid('company_id');
                $t->uuid('employment_id');
                $t->date('effective_from');
                foreach (['department_id', 'location_id', 'position_id', 'employment_type_id', 'calendar_id', 'manager_employment_id'] as $column) {
                    $t->uuid($column)->nullable();
                }
                $t->unsignedBigInteger('created_by')->nullable();
                $t->text('reason')->nullable();
                $t->timestampTz('created_at')->useCurrent();
                $t->unique(['tenant_id', 'company_id', 'id']);
                $t->unique(['tenant_id', 'company_id', 'employment_id', 'effective_from']);
                $t->index(['tenant_id', 'company_id', 'manager_employment_id']);
                $t->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies');
                $t->foreign(['tenant_id', 'company_id', 'employment_id'])->references(['tenant_id', 'company_id', 'id'])->on('employments');
                $t->foreign(['tenant_id', 'company_id', 'manager_employment_id'])->references(['tenant_id', 'company_id', 'id'])->on('employments');
                $t->foreign(['tenant_id', 'company_id', 'calendar_id'])->references(['tenant_id', 'company_id', 'id'])->on('working_calendars');
                foreach (['department' => 'departments', 'location' => 'locations', 'position' => 'positions', 'employment_type' => 'employment_types'] as $key => $table) {
                    $t->foreign(['tenant_id', 'company_id', $key.'_id'])->references(['tenant_id', 'company_id', 'id'])->on($table);
                }
            });
            DB::statement('ALTER TABLE employment_assignments ADD CONSTRAINT employment_assignments_not_self_managed CHECK (manager_employment_id IS NULL OR manager_employment_id <> employment_id)');
            foreach (self::TABLES as $table) {
                DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
                DB::statement(sprintf(self::POLICY, $table));
            }
            $this->backfill(DB::connection());
            Schema::table('employments', fn (Blueprint $t) => $t->dropColumn(['department_id', 'location_id', 'position_id']));
        });
    }

    /**
     * Copies each employment's legacy department/location/position into its initial assignment at start_date.
     * Deterministic (ID derived from the employment ID) and idempotent. The non-superuser owner is subject to FORCE RLS,
     * so FORCE is lifted only inside the caller's transaction to see every tenant's rows, then restored.
     */
    public function backfill(Connection $db): int
    {
        foreach (['employments', 'employment_assignments'] as $table) {
            $db->statement("ALTER TABLE $table NO FORCE ROW LEVEL SECURITY");
        }
        $count = $db->affectingStatement("INSERT INTO employment_assignments (id, tenant_id, company_id, employment_id, effective_from, department_id, location_id, position_id, created_at)
            SELECT md5('hr.initial_assignment:' || e.id::text)::uuid, e.tenant_id, e.company_id, e.id, e.start_date, e.department_id, e.location_id, e.position_id, coalesce(e.created_at, now())
            FROM employments e ORDER BY e.id ON CONFLICT DO NOTHING");
        foreach (['employments', 'employment_assignments'] as $table) {
            $db->statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
        }

        return $count;
    }

    public function down(): void
    {
        DB::transaction(function () {
            Schema::table('employments', function (Blueprint $t) {
                foreach (['department', 'location', 'position'] as $key) {
                    $t->uuid($key.'_id')->nullable();
                }
            });
            foreach (['employments', 'employment_assignments'] as $table) {
                DB::statement("ALTER TABLE $table NO FORCE ROW LEVEL SECURITY");
            }
            DB::statement('UPDATE employments e SET department_id = a.department_id, location_id = a.location_id, position_id = a.position_id
                FROM employment_assignments a WHERE a.tenant_id = e.tenant_id AND a.company_id = e.company_id AND a.employment_id = e.id AND a.effective_from = e.start_date');
            DB::statement('ALTER TABLE employments FORCE ROW LEVEL SECURITY');
            Schema::table('employments', function (Blueprint $t) {
                foreach (['department' => 'departments', 'location' => 'locations', 'position' => 'positions'] as $key => $table) {
                    $t->foreign(['tenant_id', 'company_id', $key.'_id'])->references(['tenant_id', 'company_id', 'id'])->on($table);
                }
            });
            foreach (array_reverse(self::TABLES) as $table) {
                Schema::dropIfExists($table);
            }
            DB::statement('ALTER TABLE employments DROP CONSTRAINT employments_probation_after_start');
            Schema::table('employments', fn (Blueprint $t) => $t->dropColumn('probation_end_date'));
            DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_profile_fields_valid');
            Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['profile_fields', 'profile_fields_version']));
        });
    }
};
