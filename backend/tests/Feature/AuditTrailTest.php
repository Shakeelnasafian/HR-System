<?php

namespace Tests\Feature;

use App\Services\Audit\SecurityEvents;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\FoundationFixture;

class AuditTrailTest extends FoundationFixture
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('fixture')->statement('TRUNCATE security_events');
    }

    private function ready(): void
    {
        $this->requireMfa();
        foreach (['workforce.read', 'workforce.write', 'audit.read'] as $p) {
            DB::connection('fixture')->table('company_grants')->insert(['tenant_id' => $this->t1, 'company_id' => $this->a, 'membership_id' => $this->membership, 'permission' => $p]);
        }
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);
    }

    private function url(string $suffix): string
    {
        return '/api/v1/companies/'.$this->a.'/'.$suffix;
    }

    private function person(string $n)
    {
        return $this->withHeader('X-Request-ID', 'client-chosen')->postJson($this->url('employees'), ['employee_number' => 'P'.$n, 'legal_name' => 'Synthetic Person', 'employment_number' => 'E'.$n, 'start_date' => '2026-01-01'])->assertCreated();
    }

    private function security(): Collection
    {
        return DB::connection('fixture')->table('security_events')->orderBy('seq')->get();
    }

    private function denied(\Closure $query): void
    {
        try {
            $query();
            $this->fail('Runtime role was not denied.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
    }

    public function test_one_request_shares_a_server_generated_correlation_id_returned_as_header(): void
    {
        $this->ready();
        $first = $this->person('1')->headers->get('X-Request-ID');
        $second = $this->person('2')->headers->get('X-Request-ID');
        $this->assertTrue(Str::isUuid($first));
        $this->assertNotSame($first, $second);
        $rows = DB::connection('fixture')->table('audit_events')->orderBy('seq')->get();
        $this->assertSame([$first, $first, $second, $second], $rows->pluck('correlation_id')->all());
        $this->assertTrue(Str::isUuid($this->getJson($this->url('employees/'.Str::uuid()))->assertNotFound()->headers->get('X-Request-ID')));
        $this->withHeader('X-Tenant-ID', 'not-a-uuid')->getJson($this->url('audit'))->assertStatus(400)->assertHeader('X-Request-ID');
    }

    public function test_listing_is_creation_ordered_and_timestamps_keep_microseconds(): void
    {
        $this->ready();
        $this->person('1');
        $this->person('2');
        $listed = $this->getJson($this->url('audit').'?per_page=3')->assertOk()->json('data');
        $this->assertSame(['employment.created', 'employee.created', 'employment.created'], array_column($listed, 'action'));
        $page2 = $this->getJson($this->url('audit').'?per_page=3&page=2')->assertOk()->json('data');
        $ordered = DB::connection('fixture')->table('audit_events')->orderByDesc('seq')->pluck('id')->all();
        $this->assertSame($ordered, array_merge(array_column($listed, 'id'), array_column($page2, 'id')));
        $db = DB::connection('fixture');
        foreach (['audit_events', 'security_events'] as $table) {
            $this->assertSame(6, (int) $db->scalar("select datetime_precision from information_schema.columns where table_name=? and column_name='occurred_at'", [$table]));
        }
        $times = $db->table('audit_events')->orderBy('seq')->pluck('occurred_at')->all();
        $this->assertCount(4, array_unique($times));
        $this->assertGreaterThan(0, (int) $db->scalar("select count(*) from audit_events where occurred_at <> date_trunc('second',occurred_at)"));
        $this->assertSame($times, $db->table('audit_events')->orderBy('occurred_at')->pluck('occurred_at')->all());
    }

    public function test_authentication_events_are_recorded_without_credentials(): void
    {
        $this->postJson('/login', ['email' => 'Member@Example.test', 'password' => 'incorrect-secret'])->assertUnprocessable();
        $this->postJson('/login', ['email' => 'nobody@example.test', 'password' => 'incorrect-secret'])->assertUnprocessable();
        $login = $this->postJson('/login', ['email' => 'member@example.test', 'password' => 'test-password-123'])->assertOk();
        $this->postJson('/logout')->assertNoContent();
        $rows = $this->security();
        $this->assertSame(['login.failed', 'login.failed', 'login.succeeded', 'logout'], $rows->pluck('event')->all());
        $this->assertSame([$this->uid, null, $this->uid, $this->uid], $rows->pluck('user_id')->all());
        $this->assertSame(SecurityEvents::hash('member@example.test'), $rows[0]->identifier_hash);
        $this->assertSame(SecurityEvents::hash('nobody@example.test'), $rows[1]->identifier_hash);
        $this->assertNull($rows[2]->identifier_hash);
        $this->assertSame($login->headers->get('X-Request-ID'), $rows[2]->correlation_id);
        $dump = $rows->toJson();
        foreach (['member@', 'nobody@', 'incorrect-secret', 'test-password-123'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $dump);
        }
    }

    public function test_lockout_is_recorded_with_hashed_identifier(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', ['email' => 'member@example.test', 'password' => 'incorrect-secret'])->assertUnprocessable();
        }
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/login', ['email' => 'member@example.test', 'password' => 'incorrect-secret'])->assertStatus(429);
        }
        $locked = $this->security()->where('event', 'login.locked_out');
        $this->assertCount(1, $locked);
        $this->assertSame(SecurityEvents::hash('member@example.test'), $locked->first()->identifier_hash);
    }

    public function test_mfa_failure_and_success_are_recorded(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        User::find($this->uid)->forceFill(['two_factor_secret' => encrypt($secret), 'two_factor_recovery_codes' => encrypt(json_encode(['recovery-test'])), 'two_factor_confirmed_at' => now()])->save();
        $this->postJson('/login', ['email' => 'member@example.test', 'password' => 'test-password-123'])->assertOk()->assertJsonPath('two_factor', true);
        $this->postJson('/two-factor-challenge', ['code' => '000000'])->assertUnprocessable();
        $this->postJson('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertNoContent();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.mfa_verified', true);
        $rows = $this->security();
        $this->assertSame(['mfa.challenged', 'mfa.challenge_failed', 'mfa.challenge_passed', 'login.succeeded'], $rows->pluck('event')->all());
        $this->assertSame([$this->uid], $rows->pluck('user_id')->unique()->values()->all());
        $this->assertStringNotContainsString($secret, $rows->toJson());
    }

    public function test_runtime_cannot_read_or_rewrite_security_or_audit_history(): void
    {
        $this->postJson('/login', ['email' => 'member@example.test', 'password' => 'incorrect-secret'])->assertUnprocessable();
        $this->ready();
        $this->person('1');
        $this->denied(fn () => DB::table('security_events')->count());
        $this->denied(fn () => DB::table('security_events')->update(['event' => 'forged']));
        $this->denied(fn () => DB::table('security_events')->delete());
        $this->denied(fn () => app(TenantContext::class)->run($this->t1, $this->uid, fn () => DB::table('audit_events')->update(['action' => 'forged'])));
        $this->denied(fn () => app(TenantContext::class)->run($this->t1, $this->uid, fn () => DB::table('audit_events')->delete()));
        $this->assertSame(1, DB::connection('fixture')->table('security_events')->count());
        $this->assertSame(2,DB::connection('fixture')->table('audit_events')->count());
    }
}
