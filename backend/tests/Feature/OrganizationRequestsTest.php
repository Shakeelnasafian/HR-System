<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationFixture;

/** Check ordering, locking and serialization kept by the Organization Form Requests, Actions and Resources. */
class OrganizationRequestsTest extends FoundationFixture
{
    private function grant(string $company, array $permissions): void
    {
        foreach ($permissions as $permission) {
            DB::connection('fixture')->table('company_grants')->insert(['tenant_id' => $this->t1, 'company_id' => $company, 'membership_id' => $this->membership, 'permission' => $permission]);
        }
    }

    private function url(string $suffix = ''): string
    {
        return rtrim('/api/v1/companies/'.$this->a.'/'.$suffix, '/');
    }

    /** @return list<string> SQL statements executed while running $request */
    private function statements(callable $request): array
    {
        $sql = [];
        DB::listen(function (QueryExecuted $query) use (&$sql) {
            $sql[] = $query->sql;
        });
        $request();

        return $sql;
    }

    public function test_malformed_route_ids_are_404_before_the_mfa_403(): void
    {
        DB::connection('fixture')->transaction(function ($db) {
            $db->statement('SET LOCAL session_replication_role = replica'); // privileged grant on a non-MFA membership
            $this->grant($this->a, ['organization.read', 'organization.write']);
        });
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);
        $uuid = (string) Str::uuid();
        // Unknown kind, malformed unit id and malformed holiday id were rejected before the company was resolved.
        $this->postJson($this->url('organization/teams'), ['code' => 'X', 'name' => 'X'])->assertNotFound();
        $this->patchJson($this->url('organization/teams/'.$uuid), ['version' => 1])->assertNotFound();
        $this->patchJson($this->url('organization/departments/not-a-uuid'), ['version' => 1])->assertNotFound();
        $this->deleteJson($this->url("calendars/$uuid/holidays/not-a-uuid"), ['version' => 1, 'reason' => 'X'])->assertNotFound();
        // Well-formed ids reach the company check, which refuses the unverified session.
        $this->postJson($this->url('organization/departments'), ['code' => 'X', 'name' => 'X'])->assertForbidden()->assertJsonPath('message', 'MFA login required.');
        $this->patchJson($this->url('organization/departments/'.$uuid), ['version' => 1])->assertForbidden();
        $this->deleteJson($this->url("calendars/$uuid/holidays/$uuid"), ['version' => 1, 'reason' => 'X'])->assertForbidden();
        $this->patchJson($this->url('calendars/not-a-uuid'), ['version' => 1, 'reason' => 'X'])->assertForbidden();
        // organization.read is not privileged: an unknown kind is still a 404 and a known one is listed.
        $this->getJson($this->url('organization/teams'))->assertNotFound();
        $this->getJson($this->url('organization/departments'))->assertOk();
    }

    public function test_unknown_children_are_validated_before_they_are_resolved(): void
    {
        $this->requireMfa();
        $this->grant($this->a, ['organization.read', 'organization.write']);
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);
        $uuid = (string) Str::uuid();
        $this->patchJson($this->url('organization/departments/'.$uuid), [])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->patchJson($this->url('organization/departments/'.$uuid), ['version' => 1])->assertNotFound();
        $this->getJson($this->url('calendars/not-a-uuid?year=26'))->assertUnprocessable();
        $this->getJson($this->url("calendars/$uuid?year=26"))->assertUnprocessable();
        $this->getJson($this->url("calendars/$uuid?year=2026"))->assertNotFound();
        foreach ([$uuid, 'not-a-uuid'] as $calendar) {
            $this->patchJson($this->url("calendars/$calendar"), [])->assertUnprocessable()->assertJsonValidationErrors(['version', 'reason']);
            $this->patchJson($this->url("calendars/$calendar"), ['version' => 1, 'reason' => 'X'])->assertNotFound();
            $this->postJson($this->url("calendars/$calendar/patterns"), ['version' => 1, 'reason' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('working_days');
            $this->postJson($this->url("calendars/$calendar/holidays"), ['version' => 1, 'reason' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('holiday_date');
            $this->deleteJson($this->url("calendars/$calendar/holidays/$uuid"), ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('reason');
            $this->deleteJson($this->url("calendars/$calendar/holidays/$uuid"), ['version' => 1, 'reason' => 'X'])->assertNotFound();
        }
        // Company settings: input checks run before the 409 version check.
        $this->grant($this->a, ['company.manage']);
        $this->patchJson($this->url(), ['version' => 9, 'reason' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson($this->url(), ['version' => 9, 'reason' => 'X', 'name' => ' '])->assertUnprocessable();
        $this->patchJson($this->url(), ['version' => 9, 'reason' => 'X', 'name' => 'Renamed'])->assertConflict();
    }

    public function test_mutations_lock_the_rows_they_depend_on(): void
    {
        $this->requireMfa();
        $this->grant($this->a, ['organization.read', 'organization.write', 'company.manage', 'workforce.read']);
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);
        $locks = fn (array $sql) => array_values(array_filter($sql, fn ($s) => str_ends_with($s, 'for update')));

        $sql = $locks($this->statements(fn () => $this->patchJson($this->url(), ['version' => 1, 'reason' => 'X', 'name' => 'Renamed'])->assertOk()));
        $this->assertCount(1, $sql);
        $this->assertStringStartsWith('select * from "companies"', $sql[0]);

        $sql = $locks($this->statements(fn () => $this->putJson($this->url('profile-fields'), ['version' => 1, 'reason' => 'X', 'enabled' => ['address']])->assertOk()));
        $this->assertCount(1, $sql);
        $this->assertStringStartsWith('select * from "companies"', $sql[0]);

        $unit = $this->postJson($this->url('organization/locations'), ['code' => 'hq', 'name' => 'HQ'])->assertCreated()->json('data.id');
        $sql = $locks($this->statements(fn () => $this->patchJson($this->url("organization/locations/$unit"), ['version' => 1, 'name' => 'Head office'])->assertOk()));
        $this->assertCount(1, $sql);
        $this->assertStringStartsWith('select * from "locations"', $sql[0]);

        $calendar = $this->postJson($this->url('calendars'), ['code' => 'STD', 'name' => 'Standard', 'reason' => 'X', 'effective_from' => '2026-01-01', 'working_days' => [1]])->assertCreated()->json('data.id');
        $sql = $this->statements(fn () => $this->postJson($this->url("calendars/$calendar/holidays"), ['version' => 1, 'reason' => 'X', 'holiday_date' => '2026-12-25', 'name' => 'X'])->assertCreated());
        $this->assertCount(1, $locks($sql));
        $this->assertStringStartsWith('select * from "working_calendars"', $locks($sql)[0]);
        // The version bump is written after the holiday insert, as before.
        $insert = array_key_first(array_filter($sql, fn ($s) => str_starts_with($s, 'insert into "calendar_holidays"')));
        $bump = array_key_first(array_filter($sql, fn ($s) => str_starts_with($s, 'update "working_calendars"')));
        $this->assertNotNull($insert);
        $this->assertGreaterThan($insert, $bump);
    }

    public function test_representations_keep_their_types(): void
    {
        $this->requireMfa();
        $this->grant($this->a, ['organization.read', 'organization.write', 'company.manage', 'workforce.read']);
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);

        $this->getJson($this->url('profile-fields'))->assertOk()->assertExactJson(['data' => [
            'enabled' => [], 'available' => ['birth_date', 'nationality', 'personal_email', 'personal_phone', 'address', 'emergency_contacts'], 'version' => 1,
        ]]);
        $this->assertSame('{"data":{"enabled":[],', substr($this->getJson($this->url('profile-fields'))->getContent(), 0, 22));
        $this->putJson($this->url('profile-fields'), ['version' => 1, 'reason' => 'X', 'enabled' => ['address', 'birth_date']])->assertOk()
            ->assertJsonPath('data.enabled', ['birth_date', 'address'])->assertJsonPath('data.version', 2);
        $this->putJson($this->url('profile-fields'), ['version' => '2', 'reason' => 'X', 'enabled' => []])->assertConflict(); // compared strictly, as before

        $created = $this->postJson($this->url('organization/positions'), ['code' => 'dev', 'name' => 'Developer'])->assertCreated();
        $id = $created->json('data.id');
        $created->assertExactJson(['data' => ['id' => $id, 'code' => 'DEV', 'name' => 'Developer', 'archived' => false, 'version' => 1]]);
        $this->patchJson($this->url("organization/positions/$id"), ['version' => 1, 'archived' => 1])->assertOk()
            ->assertExactJson(['data' => ['id' => $id, 'code' => 'DEV', 'name' => 'Developer', 'archived' => true, 'version' => 2]]);
        $this->patchJson($this->url("organization/positions/$id"), ['version' => 2])->assertOk()->assertJsonPath('data.version', 3); // a bare version still bumps
        $this->patchJson($this->url("organization/positions/$id"), ['version' => '3'])->assertConflict(); // compared strictly, as before
        $this->assertSame(['positions.created', 'positions.updated', 'positions.updated'], DB::connection('fixture')->table('audit_events')->where('resource_id', $id)->orderBy('seq')->pluck('action')->all());

        $calendar = $this->postJson($this->url('calendars'), ['code' => 'FUT', 'name' => 'Future', 'reason' => 'X', 'effective_from' => '2999-01-01', 'working_days' => [7, 6]])->assertCreated()->json('data.id');
        $this->getJson($this->url('calendars'))->assertOk()->assertExactJson(['data' => [
            ['id' => $calendar, 'code' => 'FUT', 'name' => 'Future', 'archived' => false, 'version' => 1, 'current_pattern' => null],
        ]]);
        $show = $this->getJson($this->url("calendars/$calendar"))->assertOk();
        $show->assertExactJson(['data' => ['id' => $calendar, 'code' => 'FUT', 'name' => 'Future', 'archived' => false, 'version' => 1,
            'patterns' => [['id' => $show->json('data.patterns.0.id'), 'effective_from' => '2999-01-01', 'working_days' => [6, 7]]], 'holidays' => []]]);
        $this->patchJson($this->url("calendars/$calendar"), ['version' => '1', 'reason' => 'X', 'archived' => '1'])->assertOk() // calendar versions are cast
            ->assertExactJson(['data' => ['id' => $calendar, 'code' => 'FUT', 'name' => 'Future', 'archived' => true, 'version' => 2]]);
    }
}
