<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Events commit with the domain change that caused them; the relay leases them and workers deliver after commit.
        // payload holds scalar identifiers only (enforced by App\Services\Messaging\Outbox): handlers reload authorized data at execution.
        DB::statement("CREATE TABLE outbox_events (
            id uuid PRIMARY KEY, seq bigint GENERATED ALWAYS AS IDENTITY UNIQUE, tenant_id uuid NOT NULL REFERENCES tenants (id), company_id uuid NULL,
            type varchar(80) NOT NULL, payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'), dedupe_key varchar(200) NOT NULL,
            actor_id bigint NULL, correlation_id uuid NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'delivered', 'failed', 'cancelled')),
            attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0), available_at timestamp(6) with time zone NOT NULL DEFAULT clock_timestamp(),
            lease_until timestamp(6) with time zone NULL, delivered_at timestamp(6) with time zone NULL, last_error varchar(500) NULL,
            created_at timestamp(6) with time zone NOT NULL DEFAULT clock_timestamp(),
            UNIQUE (tenant_id, id), UNIQUE (tenant_id, dedupe_key),
            FOREIGN KEY (tenant_id, company_id) REFERENCES companies (tenant_id, id),
            CHECK ((status = 'delivered') = (delivered_at IS NOT NULL)), CHECK (status = 'pending' OR lease_until IS NULL))");
        DB::statement("CREATE INDEX outbox_events_due_index ON outbox_events (tenant_id, available_at, seq) WHERE status = 'pending'");
        DB::statement("CREATE TABLE outbox_attempts (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, tenant_id uuid NOT NULL, event_id uuid NOT NULL, attempt integer NOT NULL CHECK (attempt > 0),
            started_at timestamp(6) with time zone NOT NULL DEFAULT clock_timestamp(), finished_at timestamp(6) with time zone NULL,
            outcome varchar(16) NULL CHECK (outcome IN ('delivered', 'retry', 'failed', 'cancelled', 'abandoned')), error varchar(500) NULL,
            FOREIGN KEY (tenant_id, event_id) REFERENCES outbox_events (tenant_id, id), UNIQUE (event_id, attempt),
            CHECK ((outcome IS NULL) = (finished_at IS NULL)))");
        foreach (['outbox_events', 'outbox_attempts'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_boundary ON $table USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE outbox_attempts');
        DB::statement('DROP TABLE outbox_events');
    }
};
