<?php

namespace Tests\Feature;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationFixture;

class CompanyCalendarTest extends FoundationFixture
{
    private function grant(string $company, array $permissions, bool $mfa = true): void
    {
        if ($mfa) {
            $this->requireMfa();
        }
        foreach ($permissions as $permission) {
            DB::connection('fixture')->table('company_grants')->insert(['tenant_id' => $this->t1, 'company_id' => $company, 'membership_id' => $this->membership, 'permission' => $permission]);
        }
    }

    private function ready(array $extra = []): void
    {
        $this->grant($this->a, ['organization.read', 'organization.write', ...$extra]);
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);
    }

    private function url(string $suffix = '', ?string $company = null): string
    {
        return rtrim('/api/v1/companies/'.($company ?? $this->a).'/'.$suffix, '/');
    }

    private function calendar(array $extra = []): array
    {
        return $this->postJson($this->url('calendars'), $extra + ['code' => 'std', 'name' => 'Standard week', 'reason' => 'Initial setup', 'effective_from' => '2026-01-01', 'working_days' => [5, 1, 2, 3, 4]])->assertCreated()->json('data');
    }

    private function audits(string $action)
    {
        return DB::connection('fixture')->table('audit_events')->where('action', $action)->orderBy('seq')->get();
    }

    public function test_company_settings_update_is_versioned_validated_and_audited(): void
    {
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.timezone', 'Asia/Dubai');
        $this->patchJson($this->url(), ['version' => 1, 'reason' => 'Rename', 'name' => 'Nope'])->assertNotFound(); // company.read cannot manage
        $this->grant($this->a, ['company.manage']);
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);
        foreach ([['timezone' => 'Mars/Phobos'], ['timezone' => 'asia/dubai'], ['timezone' => ''], ['name' => '   '], ['name' => str_repeat('x', 121)], []] as $invalid) {
            $this->patchJson($this->url(), $invalid + ['version' => 1, 'reason' => 'Invalid'])->assertUnprocessable();
        }
        $this->patchJson($this->url(), ['version' => 1, 'name' => 'Renamed'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $response = $this->patchJson($this->url(), ['version' => 1, 'reason' => 'Rebrand', 'name' => '  Renamed  ', 'timezone' => 'Asia/Tokyo', 'code' => 'HACKED'])->assertOk()
            ->assertExactJson(['data' => ['id' => $this->a, 'name' => 'Renamed', 'code' => 'ONE', 'timezone' => 'Asia/Tokyo', 'version' => 2]]);
        $this->patchJson($this->url(), ['version' => 1, 'reason' => 'Stale', 'name' => 'Other'])->assertConflict();
        $this->patchJson($this->url(), ['version' => 2, 'reason' => 'Same values', 'name' => 'Renamed', 'timezone' => 'Asia/Tokyo'])->assertOk()->assertJsonPath('data.version', 2);
        $audit = $this->audits('company.updated');
        $this->assertCount(1, $audit);
        $this->assertSame(['fields' => ['name', 'timezone']], json_decode($audit[0]->changes, true));
        $this->assertSame(['Rebrand', $response->headers->get('X-Request-ID')], [$audit[0]->reason, $audit[0]->correlation_id]);
        $this->assertSame('Renamed', DB::connection('fixture')->table('companies')->where('id', $this->a)->value('name'));
        // Other companies, other tenants and malformed IDs are indistinguishable 404s.
        $this->patchJson($this->url('', $this->b), ['version' => 1, 'reason' => 'Hidden', 'name' => 'X'])->assertNotFound();
        $this->patchJson('/api/v1/companies/not-a-uuid', ['version' => 1, 'reason' => 'X', 'name' => 'X'])->assertNotFound();
        $this->withHeader('X-Tenant-ID', $this->t2)->patchJson($this->url('', $this->other), ['version' => 1, 'reason' => 'X', 'name' => 'X'])->assertNotFound();
        $this->assertSame('Allowed 2', DB::connection('fixture')->table('companies')->where('id', $this->other)->value('name'));
    }

    public function test_company_manage_and_organization_write_require_a_verified_session(): void
    {
        DB::connection('fixture')->transaction(function ($db) {
            $db->statement('SET LOCAL session_replication_role = replica'); // operator bypass leaves privileged grants on a non-MFA membership
            foreach (['company.manage', 'organization.read', 'organization.write'] as $permission) {
                $db->table('company_grants')->insert(['tenant_id' => $this->t1, 'membership_id' => $this->membership, 'company_id' => $this->a, 'permission' => $permission]);
            }
        });
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);
        $this->getJson($this->url('calendars'))->assertOk()->assertJsonPath('data', []);
        $this->postJson($this->url('calendars'), ['code' => 'STD', 'name' => 'Standard', 'reason' => 'Setup', 'effective_from' => '2026-01-01', 'working_days' => [1]])->assertForbidden()->assertJsonPath('message', 'MFA login required.');
        $this->patchJson($this->url(), ['version' => 1, 'reason' => 'Rename', 'name' => 'Renamed'])->assertForbidden();
        $this->assertSame(0, DB::connection('fixture')->table('working_calendars')->count());
        $this->assertSame('Allowed 1', DB::connection('fixture')->table('companies')->where('id', $this->a)->value('name'));
        $this->requireMfa()->withHeader('X-Tenant-ID', $this->t1);
        $this->calendar();
        $this->patchJson($this->url(), ['version' => 1, 'reason' => 'Rename', 'name' => 'Renamed'])->assertOk();
    }

    public function test_calendar_permissions_are_separated_and_scoped_to_the_company(): void
    {
        $this->ready();
        $id = $this->calendar()['id'];
        DB::connection('fixture')->table('company_grants')->where('permission', 'organization.write')->delete();
        $this->getJson($this->url('calendars'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->url("calendars/$id"))->assertOk()->assertJsonPath('data.code', 'std');
        $this->postJson($this->url('calendars'), ['code' => 'X', 'name' => 'X', 'reason' => 'X', 'effective_from' => '2026-01-01', 'working_days' => [1]])->assertNotFound();
        $this->patchJson($this->url("calendars/$id"), ['version' => 1, 'reason' => 'X', 'name' => 'X'])->assertNotFound();
        $this->postJson($this->url("calendars/$id/holidays"), ['version' => 1, 'reason' => 'X', 'holiday_date' => '2026-12-25', 'name' => 'X'])->assertNotFound();
        $this->postJson($this->url("calendars/$id/patterns"), ['version' => 1, 'reason' => 'X', 'effective_from' => '2026-02-01', 'working_days' => [1]])->assertNotFound();
        DB::connection('fixture')->table('company_grants')->where('permission', 'organization.read')->delete();
        $this->getJson($this->url('calendars'))->assertNotFound(); // company.read alone does not expose calendars
        $this->grant($this->b, ['organization.read', 'organization.write']);
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);
        $this->getJson($this->url("calendars/$id", $this->b))->assertNotFound();
        $this->patchJson($this->url("calendars/$id", $this->b), ['version' => 1, 'reason' => 'X', 'archived' => true])->assertNotFound();
        $this->getJson($this->url('calendars/not-a-uuid', $this->b))->assertNotFound();
        $this->getJson($this->url('calendars', 'not-a-uuid'))->assertNotFound();
        $this->withHeader('X-Tenant-ID', $this->t2)->getJson($this->url("calendars/$id", $this->other))->assertNotFound();
        $this->assertSame('Standard week', DB::connection('fixture')->table('working_calendars')->where('id', $id)->value('name'));
    }

    public function test_calendar_creation_validates_patterns_codes_and_is_atomic(): void
    {
        $this->ready();
        foreach ([[], [0], [8], [1, 1], ['x'], [1, 2, 3, 4, 5, 6, 7, 1], '1,2'] as $days) {
            $this->postJson($this->url('calendars'), ['code' => 'BAD', 'name' => 'Bad', 'reason' => 'Invalid', 'effective_from' => '2026-01-01', 'working_days' => $days])->assertUnprocessable();
        }
        $this->postJson($this->url('calendars'), ['code' => 'STD', 'name' => 'Standard', 'effective_from' => '2026-01-01', 'working_days' => [1]])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $created = $this->postJson($this->url('calendars'), ['code' => 'Std', 'name' => 'Standard week', 'reason' => 'Initial setup', 'effective_from' => '2026-01-01', 'working_days' => [5, 1, 3]])->assertCreated();
        $created->assertExactJson(['data' => ['id' => $created->json('data.id'), 'code' => 'Std', 'name' => 'Standard week', 'archived' => false, 'version' => 1]]);
        $this->getJson($this->url('calendars/'.$created->json('data.id')))->assertOk()->assertJsonPath('data.patterns.0.working_days', [1, 3, 5])->assertJsonPath('data.holidays', []);
        $this->postJson($this->url('calendars'), ['code' => 'sTD', 'name' => 'Duplicate', 'reason' => 'Again', 'effective_from' => '2026-01-01', 'working_days' => [1]])->assertUnprocessable()->assertJsonValidationErrors('code');
        $audit = $this->audits('calendar.created');
        $this->assertCount(1, $audit);
        $this->assertSame($created->headers->get('X-Request-ID'), $audit[0]->correlation_id);
        // A failure while writing the first pattern rolls the calendar and its audit back.
        $db = DB::connection('fixture');
        $db->unprepared("CREATE FUNCTION test_fail_pattern() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'injected failure'; END \$\$;
            CREATE TRIGGER test_fail_pattern BEFORE INSERT ON calendar_patterns FOR EACH ROW EXECUTE FUNCTION test_fail_pattern();");
        try {
            $this->postJson($this->url('calendars'), ['code' => 'NEW', 'name' => 'New', 'reason' => 'Setup', 'effective_from' => '2026-01-01', 'working_days' => [1]])->assertServerError();
        } finally {
            $db->unprepared('DROP TRIGGER test_fail_pattern ON calendar_patterns; DROP FUNCTION test_fail_pattern();');
        }
        $this->assertSame(1, $db->table('working_calendars')->count());
        $this->assertSame(1, $db->table('calendar_patterns')->count());
        $this->assertCount(1, $this->audits('calendar.created'));
        $this->postJson($this->url('calendars'), ['code' => 'NEW', 'name' => 'New', 'reason' => 'Setup', 'effective_from' => '2026-01-01', 'working_days' => [1]])->assertCreated();
    }

    public function test_current_pattern_follows_the_company_timezone_date(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00 UTC'); // Asia/Dubai: 31 March; Pacific/Kiritimati: 1 April
        try {
            $this->ready(['company.manage']);
            $cal = $this->calendar(['effective_from' => '2026-03-01', 'working_days' => [1, 2, 3, 4, 5]]);
            $this->postJson($this->url("calendars/{$cal['id']}/patterns"), ['version' => 1, 'reason' => 'Four-day week', 'effective_from' => '2026-04-01', 'working_days' => [4, 3, 2, 1]])
                ->assertCreated()->assertJsonPath('data.effective_from', '2026-04-01')->assertJsonPath('data.working_days', [1, 2, 3, 4]);
            $this->postJson($this->url("calendars/{$cal['id']}/patterns"), ['version' => 2, 'reason' => 'Later', 'effective_from' => '2026-06-01', 'working_days' => [1]])->assertCreated();
            $this->postJson($this->url("calendars/{$cal['id']}/patterns"), ['version' => 3, 'reason' => 'Duplicate', 'effective_from' => '2026-06-01', 'working_days' => [2]])->assertUnprocessable()->assertJsonValidationErrors('effective_from');
            $this->calendar(['code' => 'FUT', 'name' => 'Future only', 'effective_from' => '2026-04-01', 'working_days' => [6, 7]]);
            $this->calendar(['code' => 'PAST', 'name' => 'Archived later', 'effective_from' => '2026-01-01', 'working_days' => [1]]);
            $list = $this->getJson($this->url('calendars'))->assertOk()->assertJsonCount(3, 'data');
            $this->assertSame(['Archived later', 'Future only', 'Standard week'], array_column($list->json('data'), 'name'));
            $this->assertNull($list->json('data.1.current_pattern'));
            $this->assertSame(['effective_from' => '2026-03-01', 'working_days' => [1, 2, 3, 4, 5]], $list->json('data.2.current_pattern'));
            $this->assertSame(3, $list->json('data.2.version'));
            $this->patchJson($this->url(), ['version' => 1, 'reason' => 'Moved office', 'timezone' => 'Pacific/Kiritimati'])->assertOk();
            $list = $this->getJson($this->url('calendars'))->assertOk();
            $this->assertSame(['effective_from' => '2026-04-01', 'working_days' => [1, 2, 3, 4]], $list->json('data.2.current_pattern'));
            $this->assertSame(['effective_from' => '2026-04-01', 'working_days' => [6, 7]], $list->json('data.1.current_pattern'));
            $this->assertSame(['2026-06-01', '2026-04-01', '2026-03-01'], array_column($this->getJson($this->url("calendars/{$cal['id']}"))->json('data.patterns'), 'effective_from'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_holidays_archival_and_stale_versions(): void
    {
        $this->ready();
        $cal = $this->calendar();
        $id = $cal['id'];
        $holiday = $this->postJson($this->url("calendars/$id/holidays"), ['version' => 1, 'reason' => 'Public holiday', 'holiday_date' => '2026-12-25', 'name' => 'Synthetic Holiday'])->assertCreated();
        $holiday->assertExactJson(['data' => ['id' => $holiday->json('data.id'), 'holiday_date' => '2026-12-25', 'name' => 'Synthetic Holiday']]);
        $this->postJson($this->url("calendars/$id/holidays"), ['version' => 2, 'reason' => 'Twice', 'holiday_date' => '2026-12-25', 'name' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors('holiday_date');
        $this->postJson($this->url("calendars/$id/holidays"), ['version' => 1, 'reason' => 'Stale', 'holiday_date' => '2026-12-26', 'name' => 'Stale'])->assertConflict();
        $this->postJson($this->url("calendars/$id/holidays"), ['version' => 2, 'reason' => 'New year', 'holiday_date' => '2027-01-01', 'name' => 'Synthetic New Year'])->assertCreated();
        $this->getJson($this->url("calendars/$id?year=2026"))->assertOk()->assertJsonCount(1, 'data.holidays')->assertJsonPath('data.version', 3);
        $this->assertSame(['2026-12-25', '2027-01-01'], array_column($this->getJson($this->url("calendars/$id"))->json('data.holidays'), 'holiday_date'));
        $this->getJson($this->url("calendars/$id?year=26"))->assertUnprocessable();
        // Calendar edits: stale 409, no-op without audit or version bump, archive hides it from the default list.
        $this->patchJson($this->url("calendars/$id"), ['version' => 2, 'reason' => 'Stale', 'archived' => true])->assertConflict();
        $this->patchJson($this->url("calendars/$id"), ['version' => 3, 'reason' => 'Nothing', 'name' => 'Standard week', 'archived' => false])->assertOk()->assertJsonPath('data.version', 3);
        $this->patchJson($this->url("calendars/$id"), ['version' => 3, 'reason' => 'Retired'])->assertOk()->assertJsonPath('data.version', 3);
        $this->assertCount(0, $this->audits('calendar.updated'));
        $this->patchJson($this->url("calendars/$id"), ['version' => 3, 'reason' => 'Retired', 'archived' => true, 'code' => 'NEW'])->assertOk()
            ->assertExactJson(['data' => ['id' => $id, 'code' => 'std', 'name' => 'Standard week', 'archived' => true, 'version' => 4]]);
        $this->getJson($this->url('calendars'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url('calendars?include_archived=1'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.archived', true);
        $this->postJson($this->url("calendars/$id/holidays"), ['version' => 4, 'reason' => 'Archived', 'holiday_date' => '2026-05-01', 'name' => 'X'])->assertConflict();
        $this->postJson($this->url("calendars/$id/patterns"), ['version' => 4, 'reason' => 'Archived', 'effective_from' => '2026-05-01', 'working_days' => [1]])->assertConflict();
        $this->assertSame(2, DB::connection('fixture')->table('calendar_holidays')->count());
        $this->assertSame(1, DB::connection('fixture')->table('calendar_patterns')->count());
        // Removal: version-checked, scoped to the calendar, audited with the date (never the name).
        $hid = $holiday->json('data.id');
        $this->deleteJson($this->url("calendars/$id/holidays/$hid"), ['version' => 4, 'reason' => 'Archived'])->assertConflict(); // archived calendars are frozen
        $this->assertTrue(DB::connection('fixture')->table('calendar_holidays')->where('id', $hid)->exists());
        $this->patchJson($this->url("calendars/$id"), ['version' => 4, 'reason' => 'Restored', 'archived' => false])->assertOk()->assertJsonPath('data.version', 5);
        $other = $this->calendar(['code' => 'OTH', 'name' => 'Other'])['id'];
        $foreign = $this->postJson($this->url("calendars/$other/holidays"), ['version' => 1, 'reason' => 'Other', 'holiday_date' => '2026-12-25', 'name' => 'Synthetic Other'])->assertCreated()->json('data.id');
        $this->deleteJson($this->url("calendars/$id/holidays/$foreign"), ['version' => 5, 'reason' => 'Wrong calendar'])->assertNotFound();
        $this->assertTrue(DB::connection('fixture')->table('calendar_holidays')->where('id', $foreign)->exists());
        $this->deleteJson($this->url("calendars/$id/holidays/$hid"), ['version' => 4, 'reason' => 'Stale'])->assertConflict();
        $this->deleteJson($this->url("calendars/$id/holidays/$hid"), ['version' => 5])->assertUnprocessable();
        $this->deleteJson($this->url("calendars/$id/holidays/".Str::uuid()), ['version' => 5, 'reason' => 'Unknown'])->assertNotFound();
        $removed = $this->deleteJson($this->url("calendars/$id/holidays/$hid"), ['version' => 5, 'reason' => 'Not observed'])->assertOk()->assertExactJson(['data' => ['id' => $hid, 'removed' => true]]);
        $this->deleteJson($this->url("calendars/$id/holidays/$hid"), ['version' => 6, 'reason' => 'Again'])->assertNotFound();
        $this->assertFalse(DB::connection('fixture')->table('calendar_holidays')->where('id', $hid)->exists());
        $audit = $this->audits('calendar.holiday_removed')->first();
        $this->assertSame(['code' => 'std', 'holiday_id' => $hid, 'holiday_date' => '2026-12-25'], json_decode($audit->changes, true));
        $this->assertSame(['Not observed', $removed->headers->get('X-Request-ID'), $id], [$audit->reason, $audit->correlation_id, $audit->resource_id]);
        $this->assertSame(['calendar.created', 'calendar.holiday_added', 'calendar.holiday_added', 'calendar.updated', 'calendar.updated', 'calendar.created', 'calendar.holiday_added', 'calendar.holiday_removed'],
            DB::connection('fixture')->table('audit_events')->orderBy('seq')->pluck('action')->all());
        $this->assertStringNotContainsString('Synthetic', DB::connection('fixture')->table('audit_events')->pluck('changes')->toJson());
        $this->assertSame(6, DB::connection('fixture')->table('working_calendars')->where('id', $id)->value('version'));
    }

    public function test_timezone_list_is_the_server_validation_set(): void
    {
        $this->getJson('/api/v1/timezones')->assertUnauthorized();
        $response = $this->signIn()->getJson('/api/v1/timezones')->assertOk(); // no tenant header needed
        $this->assertSame(\DateTimeZone::listIdentifiers(), $response->json('data'));
        $this->assertContains('Asia/Kolkata', $response->json('data'));
        $this->assertContains('UTC', $response->json('data'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=86400', $response->headers->get('Cache-Control'));
    }

    public function test_pattern_history_is_append_only_and_company_timezone_has_no_default(): void
    {
        foreach (['UPDATE' => false, 'DELETE' => false, 'TRUNCATE' => false, 'INSERT' => true, 'SELECT' => true] as $privilege => $held) {
            $this->assertSame($held, DB::selectOne('SELECT has_table_privilege(current_user, ?, ?) AS v', ['calendar_patterns', $privilege])->v, "calendar_patterns $privilege");
        }
        $this->assertNull(DB::connection('fixture')->selectOne("SELECT column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'companies' AND column_name = 'timezone'")->column_default);
        $this->expectException(QueryException::class);
        DB::connection('fixture')->table('companies')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->t1, 'name' => 'No zone', 'code' => 'NOZONE']);
    }

    public function test_calendar_tables_are_isolated_by_tenant_and_company(): void
    {
        $this->ready();
        $id = $this->calendar()['id'];
        $db = DB::connection('fixture');
        $foreign = (string) Str::uuid();
        $now = now();
        $db->table('working_calendars')->insert(['id' => $foreign, 'tenant_id' => $this->t2, 'company_id' => $this->other, 'code' => 'STD', 'name' => 'Other tenant', 'created_at' => $now, 'updated_at' => $now]);
        $db->table('calendar_patterns')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->t2, 'company_id' => $this->other, 'calendar_id' => $foreign, 'effective_from' => '2026-01-01', 'working_days' => '{1}', 'created_at' => $now, 'updated_at' => $now]);
        foreach (['working_calendars', 'calendar_patterns', 'calendar_holidays'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "$table must fail closed without a tenant context");
        }
        $context = app(TenantContext::class);
        $this->assertSame([$id], $context->run($this->t1, $this->uid, fn () => DB::table('working_calendars')->pluck('id')->all()));
        $this->assertSame(1, $context->run($this->t1, $this->uid, fn () => DB::table('calendar_patterns')->count()));
        $this->assertSame(0, $context->run($this->t1, $this->uid, fn () => DB::table('working_calendars')->where('id', $foreign)->update(['name' => 'Hijacked'])));
        $this->assertSame('Other tenant', $db->table('working_calendars')->where('id', $foreign)->value('name'));
        $this->getJson($this->url("calendars/$foreign"))->assertNotFound();
        $writes = [
            'other tenant row' => fn () => DB::table('calendar_holidays')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->t2, 'company_id' => $this->other, 'calendar_id' => $foreign, 'holiday_date' => '2026-05-01', 'name' => 'X']),
            'calendar of another company' => fn () => DB::table('calendar_holidays')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->t1, 'company_id' => $this->b, 'calendar_id' => $id, 'holiday_date' => '2026-05-01', 'name' => 'X']),
            'out-of-range weekday' => fn () => DB::table('calendar_patterns')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->t1, 'company_id' => $this->a, 'calendar_id' => $id, 'effective_from' => '2026-05-01', 'working_days' => '{0,1}']),
            'empty working days' => fn () => DB::table('calendar_patterns')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->t1, 'company_id' => $this->a, 'calendar_id' => $id, 'effective_from' => '2026-05-01', 'working_days' => '{}']),
            'case-insensitive duplicate code' => fn () => DB::table('working_calendars')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->t1, 'company_id' => $this->a, 'code' => 'STD', 'name' => 'X']),
        ];
        foreach ($writes as $label => $write) {
            try {
                $context->run($this->t1,$this->uid,$write);
                $this->fail("Expected the database to reject: $label");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
