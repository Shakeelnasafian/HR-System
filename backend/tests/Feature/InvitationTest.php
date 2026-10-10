<?php

namespace Tests\Feature;

use App\Jobs\DeliverOutboxEvent;
use App\Mail\InvitationMail;
use App\Services\Messaging\OutboxDelivery;
use App\Services\Messaging\OutboxRelay;
use App\Models\User;
use App\Services\Messaging\Handlers\InvitationSendHandler;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FoundationFixture;

class InvitationTest extends FoundationFixture
{
    private const PASSWORD = 'synthetic-Passw0rd-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['outbox.handlers' => ['invitation.send' => InvitationSendHandler::class], 'outbox.system_context' => true]);
        DB::connection('fixture')->statement('TRUNCATE security_events');
        Mail::fake();
    }

    private function db()
    {
        return DB::connection('fixture');
    }

    private function grant(string $member, string $company, string $permission): void
    {
        $this->db()->table('company_grants')->insert(['tenant_id' => $this->t1, 'membership_id' => $member, 'company_id' => $company, 'permission' => $permission]);
    }

    private function ready(array $permissions = ['access.manage', 'workforce.read']): void
    {
        $this->requireMfa();
        foreach ($permissions as $permission) {
            $this->grant($this->membership, $this->a, $permission);
        }
        $this->withHeader('X-Tenant-ID', $this->t1);
    }

    /** @return array{0:int,1:?string} user id and (optional) tenant 1 membership id with grants per company */
    private function person(string $email, array $grants = [], string $status = 'active'): array
    {
        $user = $this->db()->table('users')->insertGetId(['name' => 'Synthetic Person', 'email' => $email, 'password' => Hash::make(self::PASSWORD),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        if (! $grants) {
            return [$user, null];
        }
        $membership = (string) Str::uuid();
        $this->db()->table('tenant_memberships')->insert(['id' => $membership, 'tenant_id' => $this->t1, 'user_id' => $user, 'status' => $status, 'requires_mfa' => true]);
        foreach ($grants as $company => $permissions) {
            foreach ($permissions as $p) {
                $this->grant($membership, $company, $p);
            }
        }

        return [$user, $membership];
    }

    private function as(?int $id): static
    {
        // Separate browser sessions when changing identities inside one test process.
        auth()->forgetGuards();
        $this->flushSession();

        return $id === null ? $this : $this->actingAs(User::findOrFail($id), 'web')->withSession(['mfa_user_id' => $id]);
    }

    private function url(string $suffix = '', ?string $company = null): string
    {
        return '/api/v1/companies/'.($company ?? $this->a).'/invitations'.$suffix;
    }

    private function invite(string $email, array $permissions = ['company.read', 'workforce.read'])
    {
        return $this->postJson($this->url(), ['email' => $email, 'permissions' => $permissions, 'reason' => 'Synthetic onboarding']);
    }

    /** Relay claim → delivery job (prepare in system tenant transaction, deliver outside) for every due event. */
    private function deliver(): array
    {
        Queue::fake();
        app(OutboxRelay::class)->run();

        return Queue::pushed(DeliverOutboxEvent::class)->map(fn ($job) => app(OutboxDelivery::class)->run($job->tenantId, $job->eventId, $job->attempt))->values()->all();
    }

    private function link(int $index = -1): array
    {
        $mails = Mail::sent(InvitationMail::class)->values();
        $mail = $mails[$index < 0 ? count($mails) + $index : $index];
        $this->assertNull(parse_url($mail->url, PHP_URL_QUERY), 'The secret must never travel in the query string.');
        parse_str((string) parse_url($mail->url, PHP_URL_FRAGMENT), $query);

        return $query;
    }

    private function open(string $action, array $link, array $extra = [])
    {
        return $this->postJson('/api/v1/invitations/'.$action, $link + $extra);
    }

    private function newAccount(array $link)
    {
        return $this->open('accept', $link, ['name' => 'New Person', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
    }

    private function inviteAndDeliver(string $email, array $permissions = ['company.read', 'workforce.read']): array
    {
        $id = $this->invite($email, $permissions)->assertCreated()->json('data.id');
        $this->assertSame(['delivered'], $this->deliver());

        return [$id, $this->link()];
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

    /** Audit, security events and outbox rows must never carry the email, the secret or its hash. */
    private function assertNoLeak(array $secrets): void
    {
        $rows = json_encode([$this->db()->table('audit_events')->get(['changes', 'reason']), $this->db()->table('security_events')->get(),
            $this->db()->table('outbox_events')->get(['payload', 'dedupe_key', 'last_error']), $this->db()->table('outbox_attempts')->get(['error'])]);
        foreach (array_filter($secrets) as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $rows);
        }
    }

    public function test_invitations_follow_delegation_rules_and_reject_duplicates(): void
    {
        $this->ready();
        $this->person('holder@example.test', [$this->a => ['company.read']]);
        $this->invite('new@example.test', ['company.read', 'workforce.write'])->assertForbidden();
        $this->invite('new@example.test', ['workforce.read'])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->invite('new@example.test', ['company.read', 'unknown.permission'])->assertUnprocessable();
        $this->invite('MEMBER@example.test')->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->invite('Holder@Example.test')->assertUnprocessable()->assertJsonPath('errors.email.0', 'This person already has access to this company; use Permissions to change it.');
        $this->postJson($this->url(), ['email' => 'new@example.test', 'permissions' => ['company.read']])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $created = $this->invite(' New.Person@Example.TEST ', ['workforce.read', 'company.read'])->assertCreated()
            ->assertJsonPath('data.email', 'new.person@example.test')->assertJsonPath('data.permissions', ['company.read', 'workforce.read'])
            ->assertJsonPath('data.requires_mfa', true)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.version', 1);
        $id = $created->json('data.id');
        $this->invite('new.person@example.test')->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertFalse($this->invite('reader@example.test', ['company.read'])->assertCreated()->json('data.requires_mfa'));
        $this->postJson($this->url('', $this->b), ['email' => 'x@example.test', 'permissions' => ['company.read'], 'reason' => 'Other company'])->assertNotFound();
        $row = $this->db()->table('invitations')->where('id', $id)->first();
        $this->assertNull($row->token_hash);
        $event = $this->db()->table('outbox_events')->where('dedupe_key', "invitation.send:$id:1")->first();
        $this->assertEquals(['invitation.send', ['invitation' => $id, 'send' => 1], $this->a], [$event->type, json_decode($event->payload, true), $event->company_id]);
        $audit = $this->db()->table('audit_events')->where('action', 'invitation.created')->where('resource_id', $id)->first();
        $this->assertSame(['permissions' => ['company.read', 'workforce.read'], 'requires_mfa' => true], json_decode($audit->changes, true));
        $this->getJson($this->url())->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)->assertJsonMissingPath('data.0.token_hash');
        // Without access.manage in the company, invitations do not exist for the caller.
        $this->db()->table('company_grants')->where('membership_id', $this->membership)->where('permission', 'access.manage')->delete();
        $this->getJson($this->url())->assertNotFound();
        $this->assertNoLeak(['new.person@example.test', 'reader@example.test']);
    }

    public function test_outbox_handler_mails_a_link_and_resend_rotates_the_secret(): void
    {
        $this->ready();
        [$id,$link] = $this->inviteAndDeliver('alice@example.test');
        $mail = Mail::sent(InvitationMail::class)->sole();
        $event = $this->db()->table('outbox_events')->where('dedupe_key', "invitation.send:$id:1")->first();
        $this->assertTrue($mail->hasTo('alice@example.test'));
        $this->assertSame([$event->id, 'delivered'], [$mail->eventId, $event->status]);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/invitations/accept#tenant=', $mail->url);
        $this->assertSame([$this->t1, $id], [$link['tenant'], $link['invitation']]);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $link['token']);
        $hash = $this->db()->table('invitations')->where('id', $id)->value('token_hash');
        $this->assertSame(hash('sha256', $link['token']), $hash);
        $this->as(null)->open('preview', $link)->assertOk()->assertExactJson(['data' => ['tenant_name' => 'Tenant 1', 'company_name' => 'Allowed 1',
            'email_hint' => 'a***@example.test', 'expires_at' => $this->open('preview', $link)->json('data.expires_at'), 'existing_account' => false]]);
        $this->as($this->uid)->postJson($this->url("/$id/resend"), ['version' => 1, 'reason' => 'Lost email'])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'pending');
        $this->postJson($this->url("/$id/resend"), ['version' => 1, 'reason' => 'Stale'])->assertConflict();
        $this->as(null)->open('preview', $link)->assertNotFound();
        $this->assertSame(['delivered'], $this->deliver());
        $fresh = $this->link();
        $this->assertCount(2, Mail::sent(InvitationMail::class));
        $this->assertNotSame($link['token'], $fresh['token']);
        $this->open('preview', $link)->assertNotFound();
        $this->open('preview', $fresh)->assertOk();
        // A stale send (older counter) is cancelled instead of rotating the current secret.
        $this->db()->table('outbox_events')->where('dedupe_key', "invitation.send:$id:1")->update(['status' => 'pending', 'delivered_at' => null, 'available_at' => now()->subSecond()]);
        $this->assertSame(['cancelled'], $this->deliver());
        $this->open('preview', $fresh)->assertOk();
        $this->assertNoLeak(['alice@example.test', $link['token'], $fresh['token'], $hash]);
    }

    public function test_cancelled_expired_used_and_forged_links_are_indistinguishable_404s(): void
    {
        $this->ready();
        [$id,$link] = $this->inviteAndDeliver('bob@example.test');
        [$expiring,$expired] = $this->inviteAndDeliver('carol@example.test');
        $this->postJson($this->url("/$id/cancel"), ['version' => 2, 'reason' => 'Wrong person'])->assertConflict();
        $this->postJson($this->url("/$id/cancel"), ['version' => 1, 'reason' => 'Wrong person'])->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.version', 2);
        $this->postJson($this->url("/$id/cancel"), ['version' => 2, 'reason' => 'Again'])->assertConflict();
        $this->postJson($this->url("/$id/resend"), ['version' => 2, 'reason' => 'Again'])->assertConflict();
        $this->db()->table('invitations')->where('id', $expiring)->update(['expires_at' => now()->subMinute()]);
        $this->postJson($this->url("/$expiring/resend"), ['version' => 1, 'reason' => 'Too late'])->assertConflict();
        $this->getJson($this->url().'?status=all')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(['cancelled', 'expired'], array_column($this->getJson($this->url().'?status=all')->json('data'), 'status'));
        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'data');
        $this->as(null);
        $responses = [
            $this->open('preview', $link), $this->open('preview', $expired), $this->newAccount($expired),
            $this->open('preview', ['token' => Str::random(43)] + $expired), $this->open('preview', ['invitation' => (string) Str::uuid()] + $expired),
            $this->open('preview', ['tenant' => $this->t2] + $expired), $this->open('preview', ['token' => 'short'] + $expired),
        ];
        foreach ($responses as $response) {
            $response->assertNotFound()->assertExactJson(['message' => 'This invitation is invalid or has expired.']);
        }
        $this->assertSame(0, $this->db()->table('users')->where('email', 'carol@example.test')->count());
        // A lapsed invitation no longer blocks inviting the same person again.
        $this->as($this->uid)->invite('carol@example.test')->assertCreated();
        $this->assertSame('expired', $this->db()->table('invitations')->where('id', $expiring)->value('status'));
    }

    public function test_new_account_accept_creates_mfa_membership_and_grants_without_signing_in(): void
    {
        $this->ready();
        [$id,$link] = $this->inviteAndDeliver('Dana@Example.test');
        $hash = $this->db()->table('invitations')->where('id', $id)->value('token_hash');
        $this->as(null)->open('accept', $link)->assertUnprocessable()->assertJsonValidationErrors(['name', 'password']);
        $this->open('accept', $link, ['name' => 'Dana', 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->open('accept', $link, ['name' => 'Dana', 'password' => self::PASSWORD, 'password_confirmation' => 'different-Passw0rd'])->assertUnprocessable();
        $this->newAccount($link)->assertOk()->assertExactJson(['data' => ['tenant_id' => $this->t1, 'company_id' => $this->a, 'requires_mfa' => true]]);
        $this->assertGuest('web');
        $user = $this->db()->table('users')->where('email', 'dana@example.test')->first();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $membership = $this->db()->table('tenant_memberships')->where('tenant_id', $this->t1)->where('user_id', $user->id)->first();
        $this->assertSame(['active', true], [$membership->status, $membership->requires_mfa]);
        $this->assertSame(['company.read', 'workforce.read'], $this->db()->table('company_grants')->where('membership_id', $membership->id)->where('company_id', $this->a)->orderBy('permission')->pluck('permission')->all());
        $this->assertSame(0, $this->db()->table('company_grants')->where('membership_id', $membership->id)->where('company_id', '!=', $this->a)->count());
        $invitation = $this->db()->table('invitations')->where('id', $id)->first();
        $this->assertSame(['accepted', $user->id, null], [$invitation->status, $invitation->accepted_by, $invitation->token_hash]);
        $this->assertSame(2, $this->db()->table('companies')->where('id', $this->a)->value('access_version'));
        $audit = $this->db()->table('audit_events')->where('action', 'invitation.accepted')->sole();
        $this->assertSame([$this->a, $user->id, $id], [$audit->company_id, $audit->actor_id, $audit->resource_id]);
        $this->assertEquals(['membership_id' => $membership->id, 'permissions' => ['company.read', 'workforce.read'], 'requires_mfa' => true], json_decode($audit->changes, true));
        $event = $this->db()->table('security_events')->where('event', 'invitation.accepted')->sole();
        $this->assertSame([$user->id, null], [$event->user_id, $event->identifier_hash]);
        $this->newAccount($link)->assertNotFound();
        $this->open('preview', $link)->assertNotFound();
        $this->assertSame(1, $this->db()->table('users')->where('email', 'dana@example.test')->count());
        // The new member signs in normally and must enroll MFA before using the tenant.
        $this->as($user->id)->withHeader('X-Tenant-ID', $this->t1)->getJson('/api/v1/companies/'.$this->a)->assertForbidden()->assertJsonPath('message', 'MFA enrollment required.');
        $this->assertNoLeak(['dana@example.test', $link['token'], $hash, self::PASSWORD]);
    }

    public function test_existing_account_must_accept_from_its_own_session(): void
    {
        $this->ready();
        [$existing] = $this->person('Erin@Example.test');
        [$other] = $this->person('other@example.test');
        $this->db()->table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->t2, 'user_id' => $existing, 'status' => 'active', 'requires_mfa' => false]);
        [,$link] = $this->inviteAndDeliver('erin@example.test', ['company.read']);
        $this->as(null)->open('preview', $link)->assertOk()->assertJsonPath('data.existing_account', true)->assertJsonPath('data.email_hint', 'e***@example.test');
        $this->open('accept', $link)->assertUnauthorized()->assertExactJson(['message' => 'Sign in as the invited account to accept.']);
        $this->newAccount($link)->assertUnauthorized();
        $this->as($other)->open('accept', $link)->assertForbidden();
        $this->assertSame(0, $this->db()->table('tenant_memberships')->where('tenant_id', $this->t1)->whereIn('user_id', [$existing, $other])->count());
        $this->as($existing)->open('accept', $link)->assertOk()->assertJsonPath('data.requires_mfa', false);
        $membership = $this->db()->table('tenant_memberships')->where('tenant_id', $this->t1)->where('user_id', $existing)->first();
        $this->assertSame(['active', false], [$membership->status, $membership->requires_mfa]);
        $this->assertSame(['company.read'], $this->db()->table('company_grants')->where('membership_id', $membership->id)->pluck('permission')->all());
        $this->withHeader('X-Tenant-ID', $this->t1)->getJson('/api/v1/companies/'.$this->a)->assertOk();
        $this->assertSame(2, $this->db()->table('users')->whereRaw("lower(email) in ('erin@example.test','other@example.test')")->count());
    }

    public function test_revoked_membership_loses_access_and_reactivates_with_only_invited_grants(): void
    {
        $this->ready(['access.manage', 'workforce.read', 'audit.read']);
        [$target,$membership] = $this->person('frank@example.test', [$this->a => ['company.read', 'audit.read']]);
        $this->as($target)->withHeader('X-Tenant-ID', $this->t1)->getJson('/api/v1/companies/'.$this->a)->assertOk();
        $this->as($this->uid)->postJson("/api/v1/companies/{$this->a}/access/$membership/revoke-membership", ['version' => 1, 'reason' => 'Left the group'])
            ->assertOk()->assertExactJson(['data' => ['membership_id' => $membership, 'status' => 'revoked', 'access_version' => 2]]);
        $this->assertSame('revoked', $this->db()->table('tenant_memberships')->where('id', $membership)->value('status'));
        $this->assertSame(0, $this->db()->table('company_grants')->where('membership_id', $membership)->count());
        $audit = $this->db()->table('audit_events')->where('action', 'membership.revoked')->sole();
        $this->assertSame([$this->a, $membership, ['removed' => ['audit.read', 'company.read']]], [$audit->company_id, $audit->resource_id, json_decode($audit->changes, true)]);
        // The next request fails at the tenant boundary; their other tenants and sessions are untouched.
        $this->as($target)->getJson('/api/v1/companies/'.$this->a)->assertForbidden();
        $this->getJson('/api/v1/me/tenants')->assertOk()->assertJsonCount(0, 'data');
        // Revoked people are no longer members, so they can be invited again; MFA is never lowered by acceptance.
        $this->as($this->uid);
        $this->postJson("/api/v1/companies/{$this->a}/access/$membership/revoke-membership", ['version' => 2, 'reason' => 'Again'])->assertNotFound();
        [,$link] = $this->inviteAndDeliver('frank@example.test', ['company.read']);
        $this->grant($membership, $this->b, 'company.read'); // a stale grant that slipped past a revoke is discarded on reactivation
        $this->as($target)->open('accept', $link)->assertOk()->assertJsonPath('data.requires_mfa', true);
        $row = $this->db()->table('tenant_memberships')->where('id', $membership)->first();
        $this->assertSame(['active', true], [$row->status, $row->requires_mfa]);
        $this->assertSame([[$this->a, 'company.read']], $this->db()->table('company_grants')->where('membership_id', $membership)->get(['company_id', 'permission'])->map(fn ($g) => [$g->company_id, $g->permission])->all());
        $this->withHeader('X-Tenant-ID', $this->t1)->getJson('/api/v1/companies/'.$this->a)->assertOk();
        // Suspension is an operator decision that an invitation cannot lift.
        [$suspended] = $this->person('gina@example.test', [$this->b => ['company.read']], 'suspended');
        $this->as($this->uid);
        [,$link] = $this->inviteAndDeliver('gina@example.test', ['company.read']);
        $this->as($suspended)->open('accept', $link)->assertConflict();
        $this->assertSame('suspended', $this->db()->table('tenant_memberships')->where('user_id', $suspended)->value('status'));
    }

    public function test_revoke_membership_requires_authority_in_every_company_of_the_target(): void
    {
        $this->ready();
        [$target,$membership] = $this->person('hank@example.test', [$this->a => ['company.read', 'workforce.read'], $this->b => ['company.read']]);
        $revoke = fn (int $version = 1) => $this->postJson("/api/v1/companies/{$this->a}/access/$membership/revoke-membership", ['version' => $version, 'reason' => 'Offboarding']);
        $revoke()->assertForbidden()->assertJsonPath('message', 'Target has access in companies you do not administer; remove company access instead.');
        $this->postJson("/api/v1/companies/{$this->a}/access/{$this->membership}/revoke-membership", ['version' => 1, 'reason' => 'Self'])->assertForbidden();
        $this->postJson("/api/v1/companies/{$this->b}/access/$membership/revoke-membership", ['version' => 1, 'reason' => 'Not admin'])->assertNotFound();
        $this->postJson("/api/v1/companies/{$this->a}/access/".Str::uuid().'/revoke-membership', ['version' => 1, 'reason' => 'Unknown'])->assertNotFound();
        $this->assertSame('active', $this->db()->table('tenant_memberships')->where('id', $membership)->value('status'));
        $this->grant($this->membership, $this->b, 'company.read');
        $this->grant($this->membership, $this->b, 'access.manage');
        $revoke(2)->assertConflict();
        $revoke()->assertOk()->assertJsonPath('data.access_version', 2);
        $this->assertSame([2, 2], [$this->db()->table('companies')->where('id', $this->a)->value('access_version'), $this->db()->table('companies')->where('id', $this->b)->value('access_version')]);
        $audits = $this->db()->table('audit_events')->where('action', 'membership.revoked')->orderBy('company_id')->get();
        $this->assertEqualsCanonicalizing([$this->a, $this->b], $audits->pluck('company_id')->all());
        $this->assertSame([$membership], $audits->pluck('resource_id')->unique()->values()->all());
        $this->assertSame(['Offboarding'], $audits->pluck('reason')->unique()->values()->all());
        $this->as($target)->withHeader('X-Tenant-ID', $this->t1)->getJson('/api/v1/companies/'.$this->a)->assertForbidden();
        $this->assertNoLeak(['hank@example.test']);
    }

    public function test_concurrent_accepts_create_exactly_one_membership(): void
    {
        $this->ready();
        [$user] = $this->person('ivy@example.test');
        [$id,$link] = $this->inviteAndDeliver('ivy@example.test');
        $this->race($link, (string) $user);
        $membership = $this->db()->table('tenant_memberships')->where('tenant_id', $this->t1)->where('user_id', $user)->sole();
        $this->assertSame(['company.read', 'workforce.read'], $this->db()->table('company_grants')->where('membership_id', $membership->id)->orderBy('permission')->pluck('permission')->all());
        $this->assertSame(1, $this->db()->table('audit_events')->where('action', 'invitation.accepted')->where('resource_id', $id)->count());
        $this->assertSame(2, $this->db()->table('companies')->where('id', $this->a)->value('access_version'));
    }

    public function test_concurrent_new_account_accepts_create_one_account_and_a_generic_404(): void
    {
        $this->ready();
        [$id,$link] = $this->inviteAndDeliver('jo@example.test');
        $this->race($link, 'new');
        $user = $this->db()->table('users')->where('email', 'jo@example.test')->sole();
        $this->assertSame(1, $this->db()->table('tenant_memberships')->where('tenant_id', $this->t1)->where('user_id', $user->id)->count());
        $this->assertSame([$user->id, 'accepted'], [(int) $this->db()->table('invitations')->where('id', $id)->value('accepted_by'), $this->db()->table('invitations')->where('id', $id)->value('status')]);
    }

    /** Two real processes accept the same link at once: exactly one wins, the other sees the generic 404. */
    private function race(array $link, string $user): void
    {
        $barrier = storage_path('logs/invite-'.Str::uuid());
        $processes = [];
        try {
            foreach ([1, 2] as $_) {
                $process = new Process([PHP_BINARY, 'tests/Support/accept-invitation.php', $link['tenant'], $link['invitation'], $link['token'], $user, $barrier], base_path(), ['APP_ENV' => 'testing']);
                $process->setTimeout(25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($barrier.'.*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($barrier.'.*'), 'Both accepts must reach the barrier.');
            file_put_contents($barrier, 'go');
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $statuses[] = trim($process->getOutput());
            }
            sort($statuses);
            $this->assertSame(['200', '404'], $statuses);
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

    public function test_forged_or_orphaned_invitations_cannot_be_accepted_and_columns_are_locked(): void
    {
        $this->ready();
        [$reader,$readerMembership] = $this->person('reader@example.test', [$this->a => ['company.read']]);
        // A compromised runtime can insert a row, but the inviter must really hold access.manage and every invited permission.
        $token = Str::random(43);
        $forged = (string) Str::uuid();
        app(TenantContext::class)->run($this->t1, $this->uid, function () use ($forged, $reader, $token) {
            DB::table('invitations')->insert(['id' => $forged, 'tenant_id' => $this->t1, 'company_id' => $this->a, 'email' => 'mallory@example.test',
                'permissions' => json_encode(['company.read', 'access.manage']), 'requires_mfa' => true, 'expires_at' => now()->addDay(), 'invited_by' => $reader]);
            DB::table('invitations')->where('id', $forged)->update(['token_hash' => hash('sha256', $token)]);
            foreach (['email' => 'x@example.test', 'permissions' => '["company.read"]', 'invited_by' => $this->uid, 'requires_mfa' => false, 'company_id' => $this->b, 'tenant_id' => $this->t2, 'accepted_by' => $reader] as $column => $value) {
                $this->denied(fn () => DB::transaction(fn () => DB::table('invitations')->where('id', $forged)->update([$column => $value])));
            }
            $this->denied(fn () => DB::transaction(fn () => DB::table('invitations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->t1, 'company_id' => $this->a, 'email' => 'y@example.test',
                'permissions' => '["company.read"]', 'requires_mfa' => false, 'expires_at' => now()->addDay(), 'invited_by' => $this->uid, 'token_hash' => hash('sha256', 'y')])));
        });
        $link = ['tenant' => $this->t1, 'invitation' => $forged, 'token' => $token];
        $this->as(null)->open('preview', $link)->assertNotFound();
        $this->newAccount($link)->assertNotFound();
        $this->assertSame(0, $this->db()->table('users')->where('email', 'mallory@example.test')->count());
        // Orphaned: the inviter loses an invited permission after inviting; send is cancelled and the old link dies.
        $this->as($this->uid);
        $id = $this->invite('kim@example.test')->assertCreated()->json('data.id');
        $this->assertSame(['delivered'], $this->deliver());
        $link = $this->link();
        $this->db()->table('company_grants')->where('membership_id', $this->membership)->where('permission', 'workforce.read')->delete();
        $this->as(null)->open('preview', $link)->assertNotFound();
        $this->newAccount($link)->assertNotFound();
        $this->as($this->uid)->postJson($this->url("/$id/resend"), ['version' => 1, 'reason' => 'Retry'])->assertForbidden();
        $this->grant($this->membership, $this->a, 'workforce.read');
        $this->postJson($this->url("/$id/resend"), ['version' => 1, 'reason' => 'Retry'])->assertOk();
        $this->db()->table('company_grants')->where('membership_id', $this->membership)->where('permission', 'workforce.read')->delete();
        $this->assertSame(['cancelled'], $this->deliver(), 'prepare cancels sends from an inviter who no longer qualifies');
        $this->assertCount(1, Mail::sent(InvitationMail::class));
    }

    public function test_removing_inviter_authority_cancels_their_pending_invitations(): void
    {
        $this->ready();
        [$admin,$adminMembership] = $this->person('admin2@example.test', [$this->a => ['company.read', 'access.manage', 'workforce.read']]);
        [$other,$otherMembership] = $this->person('admin3@example.test', [$this->a => ['company.read', 'access.manage', 'workforce.read']]);
        $this->as($admin);
        $kept = $this->invite('lee@example.test', ['company.read'])->assertCreated()->json('data.id');
        $dropped = $this->invite('max@example.test', ['company.read', 'workforce.read'])->assertCreated()->json('data.id');
        $this->as($other);
        $byOther = $this->invite('ned@example.test', ['company.read', 'workforce.read'])->assertCreated()->json('data.id');
        $this->assertSame(['delivered', 'delivered', 'delivered'], $this->deliver());
        $link = collect(range(0, 2))->map(fn ($i) => $this->link($i))->firstWhere('invitation', $dropped);
        $this->as($this->uid)->putJson("/api/v1/companies/{$this->a}/access/$adminMembership", ['version' => 1, 'permissions' => ['company.read', 'access.manage'], 'reason' => 'Narrowed'])->assertOk();
        $status = fn ($id) => $this->db()->table('invitations')->where('id', $id)->value('status');
        $this->assertSame(['pending', 'cancelled', 'pending'], [$status($kept), $status($dropped), $status($byOther)]);
        $audit = $this->db()->table('audit_events')->where('action', 'invitation.cancelled')->sole();
        $this->assertSame([$dropped, ['cause' => 'inviter_access_removed'], 'Narrowed', $this->uid], [$audit->resource_id, json_decode($audit->changes, true), $audit->reason, $audit->actor_id]);
        $this->as(null)->open('preview', $link)->assertNotFound();
        // Revoking the inviter's membership cancels everything they still have pending.
        $this->as($this->uid)->postJson("/api/v1/companies/{$this->a}/access/$adminMembership/revoke-membership", ['version' => 2, 'reason' => 'Left'])->assertOk();
        $this->assertSame(['cancelled', 'cancelled', 'pending'], [$status($kept), $status($dropped), $status($byOther)]);
    }

    public function test_runtime_cannot_write_memberships_and_definer_functions_are_pinned_and_private(): void
    {
        $this->denied(fn () => DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->t1, 'user_id' => $this->uid, 'status' => 'active', 'requires_mfa' => false]));
        $this->denied(fn () => DB::table('tenant_memberships')->where('id', $this->membership)->update(['status' => 'active']));
        $this->denied(fn () => DB::table('tenant_memberships')->where('id', $this->membership)->delete());
        $this->denied(fn () => DB::table('tenants')->where('id', $this->t1)->update(['status' => 'active']));
        foreach (['DELETE', 'TRUNCATE'] as $privilege) {
            $this->assertFalse(DB::selectOne('SELECT has_table_privilege(current_user, ?, ?) AS v', ['invitations', $privilege])->v);
        }
        $functions = DB::select("SELECT p.proname, p.prosecdef, p.proconfig::text AS config, pg_get_userbyid(p.proowner) AS owner,
                has_function_privilege(current_user, p.oid, 'EXECUTE') AS runtime,
                EXISTS (SELECT 1 FROM aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a WHERE a.grantee = 0 AND a.privilege_type = 'EXECUTE') AS public
            FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public' AND p.prosecdef ORDER BY p.proname");
        $this->assertSame(['hr_accept_invitation', 'hr_preview_invitation', 'hr_revoke_membership'], array_column($functions, 'proname'), 'Every SECURITY DEFINER function must be reviewed here.');
        foreach ($functions as $f) {
            $this->assertSame('{"search_path=pg_catalog, public, pg_temp"}', $f->config, $f->proname);
            $this->assertNotSame(DB::selectOne('SELECT current_user AS u')->u, $f->owner);
            $this->assertTrue($f->runtime, $f->proname);
            $this->assertFalse($f->public, "$f->proname must not be executable by PUBLIC");
        }
        // Functions validate state themselves: no tenant context, garbage tokens and foreign users all fail closed.
        $this->assertSame([], DB::select('SELECT * FROM hr_preview_invitation(?::uuid, ?::uuid, ?)', [$this->t1, (string) Str::uuid(), hash('sha256', 'x')]));
        try {
            DB::select('SELECT * FROM hr_revoke_membership(?::uuid, ?::uuid, ?::uuid[])', [$this->membership, (string) Str::uuid(), '{}']);
            $this->fail('Revoke without tenant context succeeded.');
        } catch (QueryException $e) {
            $this->assertSame('42501',$e->errorInfo[0]);
        }
        try {
            DB::select('SELECT * FROM hr_accept_invitation(?::uuid, ?::uuid, ?, ?, ?::uuid)',[$this->t1, (string) Str::uuid(), hash('sha256','x'), $this->uid, (string) Str::uuid()]);
            $this->fail('Forged accept succeeded.');
        } catch (QueryException $e) {
            $this->assertSame('P0002',$e->errorInfo[0]);
        }
        $this->assertSame('',(string) DB::selectOne("SELECT coalesce(current_setting('app.tenant_id', true), '') AS v")->v);
    }

    public function test_public_endpoints_are_rate_limited_and_spa_only(): void
    {
        $link = ['tenant' => $this->t1, 'invitation' => (string) Str::uuid(), 'token' => Str::random(43)];
        $this->withoutHeader('Origin')->postJson('/api/v1/invitations/preview',$link)->assertForbidden();
        $this->withHeader('Origin','https://evil.example')->postJson('/api/v1/invitations/accept',$link)->assertForbidden();
        $this->withHeader('Origin','http://localhost');
        foreach (range(1,8) as $_) {
            $this->open('preview',$link)->assertNotFound();
        }
        $this->open('preview',$link)->assertStatus(429);
    }
}
