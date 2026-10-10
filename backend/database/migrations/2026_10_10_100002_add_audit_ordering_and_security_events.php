<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // FORCE RLS would hide rows from a non-superuser owner during the backfill; restored before commit.
        DB::statement('ALTER TABLE audit_events NO FORCE ROW LEVEL SECURITY');
        // Microsecond clock per statement (not per transaction) so events inside one request stay distinguishable.
        DB::statement('ALTER TABLE audit_events ALTER COLUMN occurred_at TYPE timestamp(6) with time zone, ALTER COLUMN occurred_at SET DEFAULT clock_timestamp()');
        DB::statement('ALTER TABLE audit_events ADD COLUMN seq bigint');
        DB::statement('UPDATE audit_events a SET seq = o.n FROM (SELECT id, row_number() OVER (ORDER BY occurred_at, id) n FROM audit_events) o WHERE o.id = a.id');
        DB::statement('ALTER TABLE audit_events ALTER COLUMN seq SET NOT NULL, ALTER COLUMN seq ADD GENERATED ALWAYS AS IDENTITY');
        DB::statement("SELECT setval(pg_get_serial_sequence('audit_events','seq'), coalesce((SELECT max(seq) FROM audit_events), 0) + 1, false)");
        DB::statement('CREATE UNIQUE INDEX audit_events_seq_unique ON audit_events (seq)');
        DB::statement('CREATE INDEX audit_events_company_seq_index ON audit_events (tenant_id, company_id, seq DESC)');
        DB::statement('ALTER TABLE audit_events FORCE ROW LEVEL SECURITY');

        // Authentication events happen before any tenant is selected (failed logins may not even map to a user),
        // so this table is global and deliberately outside tenant RLS. Runtime is granted INSERT only: it can
        // neither read nor alter the log; review happens through the owner/admin connection.
        DB::statement('CREATE TABLE security_events (
            id uuid PRIMARY KEY, seq bigint GENERATED ALWAYS AS IDENTITY UNIQUE, user_id bigint NULL, event varchar(64) NOT NULL,
            identifier_hash char(64) NULL, ip varchar(45) NULL, user_agent varchar(255) NULL, correlation_id uuid NOT NULL,
            occurred_at timestamp(6) with time zone NOT NULL DEFAULT clock_timestamp())');
        DB::statement('CREATE INDEX security_events_user_seq_index ON security_events (user_id, seq)');
        DB::statement('CREATE INDEX security_events_identifier_seq_index ON security_events (identifier_hash, seq) WHERE identifier_hash IS NOT NULL');
        DB::statement('CREATE INDEX security_events_occurred_at_index ON security_events (occurred_at)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE security_events');
        DB::statement('DROP INDEX audit_events_company_seq_index');
        DB::statement('DROP INDEX audit_events_seq_unique');
        DB::statement('ALTER TABLE audit_events DROP COLUMN seq');
        DB::statement('ALTER TABLE audit_events ALTER COLUMN occurred_at DROP DEFAULT');
    }
};
