<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationFixture;

/**
 * Order of observable checks on Workforce endpoints after the move to Form Requests: route children that must exist are
 * 404 before input is validated, transitions validate before resolving the employment, and staged validation reports
 * one stage at a time.
 */
class WorkforceRequestOrderTest extends FoundationFixture
{
    private function ready(array $permissions = ['workforce.read', 'workforce.write', 'profile.read', 'profile.write']): void
    {
        $this->requireMfa();
        foreach ($permissions as $permission) {
            DB::connection('fixture')->table('company_grants')->insert(['tenant_id' => $this->t1, 'company_id' => $this->a, 'membership_id' => $this->membership, 'permission' => $permission]);
        }
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);
    }

    private function url(string $suffix): string
    {
        return '/api/v1/companies/'.$this->a.'/'.$suffix;
    }

    private function person(string $n = '1'): array
    {
        return $this->postJson($this->url('employees'), ['employee_number' => 'P'.$n, 'legal_name' => 'Synthetic Person '.$n, 'employment_number' => 'E'.$n, 'start_date' => '2026-01-01'])->assertCreated()->json('data');
    }

    public function test_validation_is_staged_for_employee_creation_and_listing(): void
    {
        $this->ready();
        $this->postJson($this->url('employees'), ['employee_number' => 'bad number', 'start_date' => 'nope'])->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_number', 'legal_name'])->assertJsonMissingValidationErrors(['employment_number', 'start_date']);
        $this->postJson($this->url('employees'), ['employee_number' => 'P1', 'legal_name' => 'Person', 'start_date' => 'nope'])->assertUnprocessable()
            ->assertJsonValidationErrors(['employment_number', 'start_date']);
        $this->getJson($this->url('employees?per_page=500&q='.str_repeat('x', 101)))->assertUnprocessable()
            ->assertJsonValidationErrors('q')->assertJsonMissingValidationErrors('per_page');
        $this->getJson($this->url('employees?per_page=500'))->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_missing_route_children_are_404_before_validation(): void
    {
        $this->ready();
        $p = $this->person();
        $unknown = (string) Str::uuid();
        foreach ([$unknown, 'not-a-uuid'] as $id) {
            $this->postJson($this->url("employees/$id/employments"), [])->assertNotFound();
            $this->patchJson($this->url("employments/$id"), [])->assertNotFound();
            $this->postJson($this->url("employments/$id/assignments"), [])->assertNotFound();
            $this->getJson($this->url("employments/$id/assignments"))->assertNotFound();
            $this->getJson($this->url("employments/$id/reports?per_page=500"))->assertNotFound();
            $this->patchJson($this->url("employees/$id/profile"), [])->assertNotFound();
            $this->getJson($this->url("employees/$id"))->assertNotFound();
        }
        // The same requests against existing children reach validation.
        $this->postJson($this->url('employees/'.$p['id'].'/employments'), [])->assertUnprocessable();
        $this->patchJson($this->url('employments/'.$p['employment_id']), [])->assertUnprocessable();
        $this->postJson($this->url('employments/'.$p['employment_id'].'/assignments'), [])->assertUnprocessable();
        $this->getJson($this->url('employments/'.$p['employment_id'].'/reports?per_page=500'))->assertUnprocessable();
        $this->patchJson($this->url('employees/'.$p['id'].'/profile'), [])->assertUnprocessable();
        // A person whose only employment was cancelled has no reachable profile, whatever the body.
        $this->postJson($this->url('employments/'.$p['employment_id'].'/cancel'), ['version' => 1, 'reason' => 'Mistake'])->assertOk();
        $this->patchJson($this->url('employees/'.$p['id'].'/profile'), [])->assertNotFound();
    }

    public function test_transitions_validate_before_resolving_the_employment(): void
    {
        $this->ready();
        $unknown = (string) Str::uuid();
        $this->postJson($this->url("employments/$unknown/activate"), [])->assertUnprocessable()->assertJsonValidationErrors(['version', 'reason']);
        $this->postJson($this->url("employments/$unknown/end"), ['version' => 1, 'reason' => 'Left'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->postJson($this->url("employments/$unknown/activate"), ['version' => 1, 'reason' => 'Start'])->assertNotFound();
        $this->postJson($this->url('employments/not-a-uuid/activate'), [])->assertNotFound();
        $this->assertSame(0, DB::connection('fixture')->table('audit_events')->count());
    }

    public function test_write_only_profile_actor_sees_submitted_keys_and_nothing_else(): void
    {
        $this->ready(['workforce.write', 'profile.write', 'company.manage']);
        $p = $this->person();
        $this->putJson($this->url('profile-fields'), ['version' => 1, 'reason' => 'Collect', 'enabled' => ['personal_phone']])->assertOk();
        $url = $this->url('employees/'.$p['id'].'/profile');
        $this->patchJson($url, ['version' => 0, 'reason' => 'Onboarding', 'fields' => ['personal_phone' => '0100']])->assertOk()->assertExactJson(['data' => ['employee_id' => $p['id'], 'version' => 1, 'updated' => ['personal_phone']]]);
        // Numerically equal strings are still different values.
        $this->patchJson($url, ['version' => 1, 'reason' => 'Correction', 'fields' => ['personal_phone' => '100']])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame('100', DB::connection('fixture')->table('employee_profiles')->where('employee_id', $p['id'])->value('personal_phone'));
        $changes = DB::connection('fixture')->table('audit_events')->where('action', 'profile.updated')->orderBy('seq')->pluck('changes')->map(fn ($c) => json_decode($c, true))->all();
        $this->assertSame([['fields' => ['personal_phone'], 'submitted' => ['personal_phone']], ['fields' => ['personal_phone'], 'submitted' => ['personal_phone']]], $changes);
    }
}
