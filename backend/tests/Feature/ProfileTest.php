<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FoundationFixture;

class ProfileTest extends FoundationFixture
{
    private const EMAIL = 'private.person@example.test';

    private function grant(string $company, array $permissions): void
    {
        foreach ($permissions as $permission) {
            DB::connection('fixture')->table('company_grants')->insert(['tenant_id' => $this->t1, 'company_id' => $company, 'membership_id' => $this->membership, 'permission' => $permission]);
        }
    }

    private function ready(array $permissions = ['workforce.read', 'workforce.write', 'profile.read', 'profile.write', 'company.manage']): void
    {
        $this->requireMfa();
        $this->grant($this->a, $permissions);
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);
    }

    private function url(string $suffix, ?string $company = null): string
    {
        return '/api/v1/companies/'.($company ?? $this->a).'/'.$suffix;
    }

    private function person(string $n = '1', ?string $company = null): array
    {
        return $this->postJson($this->url('employees', $company), ['employee_number' => 'P'.$n, 'legal_name' => 'Synthetic Person '.$n, 'employment_number' => 'E'.$n, 'start_date' => '2026-01-01'])->assertCreated()->json('data');
    }

    private function enable(array $fields, int $version = 1): void
    {
        $this->putJson($this->url('profile-fields'), ['version' => $version, 'reason' => 'Collect necessary fields', 'enabled' => $fields])->assertOk();
    }

    private function audits(string $action)
    {
        return DB::connection('fixture')->table('audit_events')->where('action', $action)->orderBy('seq')->get();
    }

    public function test_field_configuration_defaults_to_nothing_and_is_versioned_and_audited(): void
    {
        $this->ready(['workforce.read']);
        $this->getJson($this->url('profile-fields'))->assertOk()->assertExactJson(['data' => ['enabled' => [], 'available' => ['birth_date', 'nationality', 'personal_email', 'personal_phone', 'address', 'emergency_contacts'], 'version' => 1]]);
        $this->putJson($this->url('profile-fields'), ['version' => 1, 'reason' => 'No grant', 'enabled' => ['birth_date']])->assertNotFound();
        $this->grant($this->a, ['company.manage']);
        foreach ([['enabled' => ['salary']], ['enabled' => ['birth_date', 'birth_date']], ['enabled' => 'birth_date'], []] as $invalid) {
            $this->putJson($this->url('profile-fields'), $invalid + ['version' => 1, 'reason' => 'Invalid'])->assertUnprocessable();
        }
        $this->putJson($this->url('profile-fields'), ['version' => 1, 'enabled' => ['address']])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->putJson($this->url('profile-fields'), ['version' => 1, 'reason' => 'Policy', 'enabled' => ['personal_email', 'birth_date']])->assertOk()
            ->assertJsonPath('data.enabled', ['birth_date', 'personal_email'])->assertJsonPath('data.version', 2);
        $this->putJson($this->url('profile-fields'), ['version' => 1, 'reason' => 'Stale', 'enabled' => []])->assertConflict();
        $this->putJson($this->url('profile-fields'), ['version' => 2, 'reason' => 'Same', 'enabled' => ['birth_date', 'personal_email']])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($this->url('profile-fields'), ['version' => 2, 'reason' => 'Narrow', 'enabled' => ['birth_date']])->assertOk()->assertJsonPath('data.version', 3);
        $audit = $this->audits('profile_fields.updated');
        $this->assertCount(2, $audit);
        $this->assertSame(['enabled' => ['birth_date', 'personal_email'], 'disabled' => []], json_decode($audit[0]->changes, true));
        $this->assertSame(['enabled' => [], 'disabled' => ['personal_email']], json_decode($audit[1]->changes, true));
        $this->putJson($this->url('profile-fields', $this->b), ['version' => 1, 'reason' => 'Hidden', 'enabled' => []])->assertNotFound();
    }

    public function test_workforce_permissions_do_not_reach_the_profile(): void
    {
        $this->ready(['workforce.read', 'workforce.write', 'company.manage']);
        $p = $this->person();
        $this->enable(['birth_date']);
        $this->getJson($this->url('employees/'.$p['id'].'/profile'))->assertNotFound();
        $this->patchJson($this->url('employees/'.$p['id'].'/profile'), ['version' => 0, 'reason' => 'No grant', 'fields' => ['birth_date' => '1990-01-01']])->assertNotFound();
        $this->grant($this->a, ['profile.write']); // write does not imply read
        $this->getJson($this->url('employees/'.$p['id'].'/profile'))->assertNotFound();
        $url = $this->url('employees/'.$p['id'].'/profile');
        $this->patchJson($url, ['version' => 0, 'reason' => 'Onboarding', 'fields' => ['birth_date' => '1990-01-01']])->assertOk()
            ->assertExactJson(['data' => ['employee_id' => $p['id'], 'version' => 1, 'updated' => ['birth_date']]]);
        // A write-only actor cannot confirm a guessed value: a no-op and a real change look the same, and both are audited.
        $same = $this->patchJson($url, ['version' => 1, 'reason' => 'Guess', 'fields' => ['birth_date' => '1990-01-01']])->assertOk()->json('data');
        $different = $this->patchJson($url, ['version' => 2, 'reason' => 'Guess', 'fields' => ['birth_date' => '1991-01-01']])->assertOk()->json('data');
        $this->assertSame([['birth_date'], ['birth_date'], 2, 3], [$same['updated'], $different['updated'], $same['version'], $different['version']]);
        $updated = $this->audits('profile.updated');
        $this->assertCount(3, $updated);
        $this->assertSame(['fields' => [], 'submitted' => ['birth_date']], json_decode($updated[1]->changes, true));
        $this->assertSame(['fields' => ['birth_date'], 'submitted' => ['birth_date']], json_decode($updated[2]->changes, true));
        $this->assertSame('Guess', $updated[1]->reason);
        $this->assertCount(0, $this->audits('profile.viewed'));
    }

    public function test_profile_permissions_require_a_verified_session(): void
    {
        DB::connection('fixture')->transaction(function ($db) {
            $db->statement('SET LOCAL session_replication_role = replica'); // operator bypass leaves privileged grants on a non-MFA membership
            foreach (['profile.read', 'profile.write'] as $permission) {
                $db->table('company_grants')->insert(['tenant_id' => $this->t1, 'membership_id' => $this->membership, 'company_id' => $this->a, 'permission' => $permission]);
            }
        });
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);
        $id = '00000000-0000-4000-8000-000000000001';
        $this->getJson($this->url("employees/$id/profile"))->assertForbidden()->assertJsonPath('message', 'MFA login required.');
        $this->patchJson($this->url("employees/$id/profile"), ['version' => 0, 'reason' => 'X', 'fields' => ['birth_date' => null]])->assertForbidden();
        $this->assertCount(0, $this->audits('profile.viewed'));
    }

    public function test_reads_are_audited_writes_are_versioned_and_values_stay_out_of_audit_and_directory(): void
    {
        $this->ready();
        $p = $this->person();
        $url = $this->url('employees/'.$p['id'].'/profile');
        $this->getJson($url)->assertOk()->assertExactJson(['data' => ['employee_id' => $p['id'], 'version' => 0, 'fields' => []]])->assertHeader('Cache-Control', 'no-store, private');
        $this->enable(['birth_date', 'personal_email', 'emergency_contacts', 'address']);
        $this->patchJson($url, ['version' => 0, 'reason' => 'Onboarding', 'fields' => ['nationality' => 'Synthetic']])->assertUnprocessable()->assertJsonValidationErrors('fields.nationality');
        foreach ([['birth_date' => '2999-01-01'], ['personal_email' => 'not-an-email'], ['address' => str_repeat('x', 1001)], ['emergency_contacts' => array_fill(0, 6, ['name' => 'A', 'relationship' => 'B', 'phone' => '1'])],
            ['emergency_contacts' => [['name' => 'A', 'relationship' => 'B', 'phone' => '1', 'medical' => 'x']]], ['emergency_contacts' => [['name' => 'A']]], ['emergency_contacts' => ['a' => ['name' => 'A', 'relationship' => 'B', 'phone' => '1']]]] as $invalid) {
            $this->patchJson($url, ['version' => 0, 'reason' => 'Invalid', 'fields' => $invalid])->assertUnprocessable();
        }
        $this->patchJson($url, ['version' => 0, 'fields' => ['birth_date' => '1990-05-17']])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $contact = ['name' => 'Synthetic Contact', 'relationship' => 'Sibling', 'phone' => '+000 555 0100'];
        $this->patchJson($url, ['version' => 0, 'reason' => 'Onboarding', 'fields' => ['birth_date' => '1990-05-17', 'personal_email' => self::EMAIL, 'emergency_contacts' => [$contact]]])->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.updated', ['birth_date', 'personal_email', 'emergency_contacts']);
        $this->patchJson($url, ['version' => 0, 'reason' => 'Stale', 'fields' => ['birth_date' => null]])->assertConflict();
        // Readers learn the real diff; the no-op is still versioned and audited.
        $this->patchJson($url, ['version' => 1, 'reason' => 'No change', 'fields' => ['birth_date' => '1990-05-17', 'emergency_contacts' => [$contact]]])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.updated', []);
        $this->getJson($url)->assertOk()->assertExactJson(['data' => ['employee_id' => $p['id'], 'version' => 2, 'fields' => ['birth_date' => '1990-05-17', 'personal_email' => self::EMAIL, 'address' => null, 'emergency_contacts' => [$contact]]]]);
        $this->patchJson($url, ['version' => 2, 'reason' => 'Correction', 'fields' => ['birth_date' => null]])->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.updated', ['birth_date']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.fields.birth_date', null)->assertJsonPath('data.fields.personal_email', self::EMAIL);
        $viewed = $this->audits('profile.viewed');
        $this->assertCount(3, $viewed);
        $this->assertSame(['fields' => ['birth_date', 'personal_email', 'address', 'emergency_contacts']], json_decode($viewed[2]->changes, true));
        $this->assertSame($p['id'], $viewed[2]->resource_id);
        $updated = $this->audits('profile.updated');
        $this->assertCount(3, $updated);
        $this->assertSame(['fields' => [], 'submitted' => ['birth_date', 'emergency_contacts']], json_decode($updated[1]->changes, true));
        $this->assertSame(['fields' => ['birth_date'], 'submitted' => ['birth_date']], json_decode($updated[2]->changes, true));
        $everything = DB::connection('fixture')->table('audit_events')->get()->toJson();
        foreach ([self::EMAIL, '1990-05-17', 'Synthetic Contact', '555 0100'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
        // Directory, detail and assignment payloads use the safe field set only.
        foreach ([$this->url('employees'), $this->url('employees?q=Synthetic'), $this->url('employees/'.$p['id']), $this->url('employments/'.$p['employment_id'].'/assignments')] as $safe) {
            $body = $this->getJson($safe)->assertOk()->getContent();
            foreach ([self::EMAIL, 'Synthetic Contact', 'birth_date', 'personal_email', 'emergency_contacts'] as $secret) {
                $this->assertStringNotContainsString($secret, $body, $safe);
            }
        }
    }

    public function test_birth_date_cannot_be_after_the_company_date(): void
    {
        $this->ready();
        $p = $this->person();
        $this->enable(['birth_date']);
        $url = $this->url('employees/'.$p['id'].'/profile');
        Carbon::setTestNow(Carbon::parse('2026-06-30 21:00:00', 'UTC')); // 2026-07-01 in Asia/Dubai
        try {
            DB::connection('fixture')->table('companies')->where('id', $this->a)->update(['timezone' => 'UTC']);
            $this->patchJson($url, ['version' => 0, 'reason' => 'Newborn', 'fields' => ['birth_date' => '2026-07-01']])->assertUnprocessable()->assertJsonValidationErrors('fields.birth_date');
            DB::connection('fixture')->table('companies')->where('id', $this->a)->update(['timezone' => 'Asia/Dubai']);
            $this->patchJson($url, ['version' => 0, 'reason' => 'Newborn', 'fields' => ['birth_date' => '2026-07-01']])->assertOk();
            $this->patchJson($url, ['version' => 1, 'reason' => 'Future', 'fields' => ['birth_date' => '2026-07-02']])->assertUnprocessable();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_disabled_fields_are_hidden_but_retained(): void
    {
        $this->ready();
        $p = $this->person();
        $url = $this->url('employees/'.$p['id'].'/profile');
        $this->enable(['birth_date', 'personal_email']);
        $this->patchJson($url, ['version' => 0, 'reason' => 'Onboarding', 'fields' => ['birth_date' => '1990-05-17', 'personal_email' => self::EMAIL]])->assertOk();
        $this->enable(['birth_date'], 2);
        $this->getJson($url)->assertOk()->assertExactJson(['data' => ['employee_id' => $p['id'], 'version' => 1, 'fields' => ['birth_date' => '1990-05-17']]]);
        $this->patchJson($url, ['version' => 1, 'reason' => 'Not collected', 'fields' => ['personal_email' => null]])->assertUnprocessable()->assertJsonValidationErrors('fields.personal_email');
        $this->assertSame(self::EMAIL, DB::connection('fixture')->table('employee_profiles')->where('employee_id', $p['id'])->value('personal_email'));
        $this->enable(['birth_date', 'personal_email'], 3);
        $this->getJson($url)->assertOk()->assertJsonPath('data.fields.personal_email', self::EMAIL);
    }

    public function test_profile_requires_a_draft_or_active_employment_in_the_route_company(): void
    {
        $this->ready();
        $p = $this->person();
        $this->enable(['birth_date']);
        $url = $this->url('employees/'.$p['id'].'/profile');
        $this->getJson($url)->assertOk();
        $this->postJson($this->url('employments/'.$p['employment_id'].'/activate'), ['version' => 1, 'reason' => 'Start'])->assertOk();
        $this->getJson($url)->assertOk();
        $this->postJson($this->url('employments/'.$p['employment_id'].'/end'), ['version' => 2, 'reason' => 'Left', 'end_date' => '2026-02-01'])->assertOk();
        $this->getJson($url)->assertNotFound(); // former employees: policy decision pending
        $this->patchJson($url, ['version' => 0, 'reason' => 'X', 'fields' => ['birth_date' => null]])->assertNotFound();
        $cancelled = $this->person('2');
        $this->postJson($this->url('employments/'.$cancelled['employment_id'].'/cancel'), ['version' => 1, 'reason' => 'Mistake'])->assertOk();
        $this->getJson($this->url('employees/'.$cancelled['id'].'/profile'))->assertNotFound();
        // Grants in another company do not reach this company's people, and foreign/malformed IDs are 404.
        $this->grant($this->b, ['workforce.write', 'profile.read']);
        $hidden = $this->person('3', $this->b);
        $this->getJson($this->url('employees/'.$hidden['id'].'/profile'))->assertNotFound();
        $this->getJson($this->url('employees/'.$hidden['id'].'/profile', $this->b))->assertOk();
        $this->getJson($this->url('employees/not-a-uuid/profile'))->assertNotFound();
        $this->withHeader('X-Tenant-ID', $this->t2)->getJson($this->url('employees/'.$p['id'].'/profile', $this->other))->assertNotFound();
    }
}
