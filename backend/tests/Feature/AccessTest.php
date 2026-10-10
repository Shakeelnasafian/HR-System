<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FoundationFixture;

class AccessTest extends FoundationFixture
{
    private string $target;

    private int $targetUser;

    private function grant(string $member, string $company, string $permission): void
    {
        DB::connection('fixture')->table('company_grants')->insert(['tenant_id' => $this->t1, 'membership_id' => $member, 'company_id' => $company, 'permission' => $permission]);
    }

    private function ready(array $permissions = ['access.manage', 'workforce.read']): void
    {
        $this->requireMfa();
        foreach ($permissions as $permission) {
            $this->grant($this->membership, $this->a, $permission);
        }
        $db = DB::connection('fixture');
        $this->targetUser = $db->table('users')->insertGetId(['name' => 'Target Member', 'email' => 'target@example.test', 'password' => Hash::make('synthetic-password')]);
        $this->target = (string) Str::uuid();
        $db->table('tenant_memberships')->insert(['id' => $this->target, 'tenant_id' => $this->t1, 'user_id' => $this->targetUser, 'status' => 'active', 'requires_mfa' => true]);
        $this->grant($this->target, $this->a, 'company.read');
        User::findOrFail($this->uid)->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
        $this->signIn()->withSession(['mfa_user_id' => $this->uid])->withHeader('X-Tenant-ID', $this->t1);
    }

    private function asUser(int $id): static
    {
        // Separate browser sessions when changing identities inside one test process.
        auth()->forgetGuards();
        $this->flushSession();

        return $this->actingAs(User::findOrFail($id), 'web')->withSession(['mfa_user_id' => $id])->withHeader('Origin', 'http://localhost');
    }

    private function endpoint(?string $company = null, ?string $member = null): string
    {
        return '/api/v1/companies/'.($company ?? $this->a).'/access'.($member ? '/'.$member : '');
    }

    private function change(array $permissions, int $version = 1, ?string $member = null)
    {
        return $this->putJson($this->endpoint(null, $member ?? $this->target), ['version' => $version, 'permissions' => $permissions, 'reason' => 'Approved synthetic access review']);
    }

    public function test_only_admin_with_fresh_mfa_can_inspect_existing_company_members(): void
    {
        $this->ready();
        $this->getJson($this->endpoint())->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('actor_membership_id', $this->membership)->assertJsonPath('access_version', 1)->assertJsonMissingPath('data.0.password');
        $this->getJson($this->endpoint($this->b))->assertNotFound();
        $this->withSession(['mfa_user_id' => null])->getJson($this->endpoint())->assertForbidden();
        $this->withSession(['mfa_user_id' => $this->uid]);
        DB::connection('fixture')->table('company_grants')->where('membership_id', $this->membership)->where('permission', 'access.manage')->delete();
        $this->getJson($this->endpoint())->assertNotFound();
    }

    public function test_grant_and_revoke_are_versioned_audited_and_effective_immediately(): void
    {
        $this->ready();
        $this->change(['company.read', 'workforce.read'])->assertOk()->assertJsonPath('data.access_version', 2);
        $this->change(['company.read'], 1)->assertConflict();
        User::find($this->targetUser)->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
        $this->asUser($this->targetUser)->getJson('/api/v1/companies/'.$this->a.'/employees')->assertOk();
        $this->asUser($this->uid);
        $this->change(['company.read'], 2)->assertOk()->assertJsonPath('data.access_version', 3);
        $this->asUser($this->targetUser)->getJson('/api/v1/companies/'.$this->a.'/employees')->assertNotFound();
        $this->assertSame(2, DB::connection('fixture')->table('audit_events')->where('action', 'membership.company_permissions.updated')->count());
    }

    public function test_self_changes_escalation_and_foreign_scopes_are_denied(): void
    {
        $this->ready();
        $this->change([], 1, $this->membership)->assertForbidden();
        $this->change(['company.read', 'workforce.write'])->assertForbidden();
        $this->grant($this->membership, $this->b, 'workforce.write');
        $this->change(['company.read', 'workforce.write'])->assertForbidden();
        $foreign = DB::connection('fixture')->table('tenant_memberships')->where('tenant_id', $this->t2)->value('id');
        $this->change(['company.read'], 1, $foreign)->assertNotFound();
        $this->putJson($this->endpoint($this->b, $this->target), ['version' => 1, 'permissions' => [], 'reason' => 'Other company'])->assertNotFound();
        $this->assertSame(0, DB::connection('fixture')->table('audit_events')->count());
    }

    public function test_unheld_existing_grants_cannot_be_removed_but_can_be_preserved(): void
    {
        $this->ready();
        $this->grant($this->target, $this->a, 'audit.read');
        $this->change(['company.read', 'workforce.read'])->assertForbidden();
        $this->change(['company.read', 'audit.read', 'workforce.read'])->assertOk();
    }

    public function test_invalid_permissions_inactive_membership_and_mfa_policy_fail_closed(): void
    {
        $this->ready();
        $this->change(['company.read', 'unknown.permission'])->assertUnprocessable();
        $this->change(['company.read', 'company.read'])->assertUnprocessable();
        $this->change(['workforce.read'])->assertUnprocessable();
        DB::connection('fixture')->table('tenant_memberships')->where('id', $this->target)->update(['requires_mfa' => false]);
        $this->change(['company.read', 'workforce.read'])->assertUnprocessable();
        DB::connection('fixture')->table('tenant_memberships')->where('id', $this->target)->update(['requires_mfa' => true, 'status' => 'suspended']);
        $this->change(['company.read', 'workforce.read'])->assertConflict();
        $this->assertSame(0, DB::connection('fixture')->table('audit_events')->count());
    }

    public function test_noop_is_not_duplicated_and_company_access_removal_hides_member(): void
    {
        $this->ready();
        $this->change(['company.read'])->assertOk()->assertJsonPath('data.access_version', 1);
        $this->assertSame(0, DB::connection('fixture')->table('audit_events')->count());
        $this->change([])->assertOk()->assertJsonPath('data.access_version', 2);
        $this->getJson($this->endpoint())->assertOk()->assertJsonCount(1, 'data');
        $this->change(['company.read'], 2)->assertNotFound();
        $this->assertSame(1, DB::connection('fixture')->table('audit_events')->count());
    }

    public function test_concurrent_grant_edits_cannot_silently_overwrite_one_another(): void
    {
        $this->ready(['access.manage', 'workforce.read', 'organization.read']);
        $barrier = storage_path('logs/access-'.Str::uuid());
        $processes = [];
        try {
            foreach (['workforce.read', 'organization.read'] as $permission) {
                $process = new Process([PHP_BINARY, 'tests/Support/change-access.php', $this->t1, (string) $this->uid, $this->a, $this->target, json_encode(['company.read', $permission]), $barrier], base_path(), ['APP_ENV' => 'testing']);
                $process->setTimeout(25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($barrier.'.*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($barrier.'.*'), 'Both writers must reach the barrier.');
            file_put_contents($barrier, 'go');
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $statuses[] = trim($process->getOutput());
            }
            sort($statuses);
            $this->assertSame(['200', '409'], $statuses);
            $this->assertSame(2, DB::connection('fixture')->table('companies')->where('id', $this->a)->value('access_version'));
            $this->assertSame(1, DB::connection('fixture')->table('audit_events')->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($barrier.'*') as $file) {
                unlink($file);
            }
        }
    }
}
