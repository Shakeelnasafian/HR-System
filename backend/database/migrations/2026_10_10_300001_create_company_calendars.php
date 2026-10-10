<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Company-owned working calendars. No weekend or holiday presets: every pattern and holiday is entered explicitly.
return new class extends Migration
{
    private const TABLES = ['working_calendars', 'calendar_patterns', 'calendar_holidays'];

    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->unsignedInteger('version')->default(1));
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_version_positive CHECK (version >= 1)');
        Schema::create('working_calendars', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('company_id');
            $t->string('code', 30);
            $t->string('name', 120);
            $t->boolean('archived')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'company_id', 'id']);
            $t->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies');
        });
        DB::statement('CREATE UNIQUE INDEX working_calendars_code_unique ON working_calendars (tenant_id, company_id, lower(code))');
        DB::statement("ALTER TABLE working_calendars ADD CONSTRAINT working_calendars_valid CHECK (btrim(code) <> '' AND btrim(name) <> '' AND version >= 1)");
        foreach (['calendar_patterns' => fn (Blueprint $t) => $t->date('effective_from'), 'calendar_holidays' => function (Blueprint $t) {
            $t->date('holiday_date');
            $t->string('name', 120);
        }] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns, $table) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id');
                $t->uuid('company_id');
                $t->uuid('calendar_id');
                $columns($t);
                $t->timestampsTz();
                $t->unique(['calendar_id', $table === 'calendar_patterns' ? 'effective_from' : 'holiday_date']);
                $t->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies');
                $t->foreign(['tenant_id', 'company_id', 'calendar_id'])->references(['tenant_id', 'company_id', 'id'])->on('working_calendars');
            });
        }
        // ISO weekdays 1=Mon..7=Sun, one-dimensional, non-empty, no NULLs. Distinct/sorted is normalised by the application.
        DB::statement('ALTER TABLE calendar_patterns ADD COLUMN working_days smallint[] NOT NULL');
        DB::statement("ALTER TABLE calendar_patterns ADD CONSTRAINT calendar_patterns_working_days_valid CHECK (array_ndims(working_days) = 1 AND cardinality(working_days) BETWEEN 1 AND 7
            AND array_position(working_days, NULL) IS NULL AND working_days <@ '{1,2,3,4,5,6,7}'::smallint[])");
        DB::statement("ALTER TABLE calendar_holidays ADD CONSTRAINT calendar_holidays_name_present CHECK (btrim(name) <> '')");
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_boundary ON $table USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_version_positive');
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('version'));
    }
};
