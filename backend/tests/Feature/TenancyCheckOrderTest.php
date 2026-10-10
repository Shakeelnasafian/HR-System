<?php

namespace Tests\Feature;

use App\Services\Messaging\Handlers\InvitationSendHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\FoundationFixture;

/**
 * Order of observable checks on the Tenancy endpoints, which Form Requests must keep: malformed child ids, self-changes
 * (403) and unknown or foreign children (404) are refused before the body is validated (422); invitation links check the
 * origin (403) and the selector (404) before validating a new account's name and password.
 */
class TenancyCheckOrderTest extends FoundationFixture
{
    private string $target;

    protected function setUp(): void
    {
        parent::setUp();
        config(['outbox.handlers' => ['invitation.send' => InvitationSendHandler::class]]);
        Mail::fake();
        $this->requireMfa();
        $db = DB::connection('fixture');
        foreach ([$this->a, $this->b] as $company) {
            foreach (['company.read', 'access.manage', 'workforce.read'] as $permission) {
                $db->table('company_grants')->insertOrIgnore(['tenant_id' => $this->t1, 'membership_id' => $this->membership, 'company_id' => $company, 'permission' => $permission]);
            }
        }
        $user = $db->table('users')->insertGetId(['name' => 'Target Member', 'email' => 'target@example.test', 'password' => Hash::make('synthetic-password')]);
        $this->target = (string) Str::uuid();
        $db->table('tenant_memberships')->insert(['id' => $this->target, 'tenant_id' => $this->t1, 'user_id' => $user, 'status' => 'active', 'requires_mfa' => true]);
        $db->table('company_grants')->insert(['tenant_id' => $this->t1, 'membership_id' => $this->target, 'company_id' => $this->b, 'permission' => 'company.read']);
        $this->withHeader('X-Tenant-ID', $this->t1);
    }

    private function c(string $company, string $path): string
    {
        return "/api/v1/companies/$company/$path";
    }

    public function test_malformed_child_ids_are_404_before_validation(): void
    {
        foreach (['permission-bundles/not-a-uuid/archive', 'access/not-a-uuid/revoke-membership', 'invitations/not-a-uuid/resend', 'invitations/not-a-uuid/cancel'] as $path) {
            $this->postJson($this->c($this->a, $path), [])->assertNotFound();
            $this->postJson($this->c($this->other, $path), [])->assertNotFound();
        }
        $this->putJson($this->c($this->a, 'access/not-a-uuid'), [])->assertNotFound();
    }

    public function test_unknown_or_foreign_bundle_is_404_before_validation(): void
    {
        $foreign = $this->postJson($this->c($this->b, 'permission-bundles'), ['name' => 'Reader', 'permissions' => ['company.read'], 'reason' => 'Setup'])->assertCreated()->json('data.id');
        $this->postJson($this->c($this->a, 'permission-bundles/'.Str::uuid().'/archive'), [])->assertNotFound();
        $this->postJson($this->c($this->a, "permission-bundles/$foreign/archive"), [])->assertNotFound();
        $this->postJson($this->c($this->b, "permission-bundles/$foreign/archive"), [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($this->c($this->b, "permission-bundles/$foreign/archive"), ['reason' => 'Retired'])->assertOk()->assertExactJson(['data' => ['id' => $foreign, 'archived' => true]]);
    }

    public function test_self_change_and_unknown_or_foreign_member_are_refused_before_validation(): void
    {
        $this->putJson($this->c($this->a, 'access/'.$this->membership), [])->assertForbidden()
            ->assertJsonPath('message', 'You cannot change your own permissions. Ask another authorized administrator.');
        $this->putJson($this->c($this->a, 'access/'.Str::uuid()), [])->assertNotFound();
        // The target has access only in company B.
        $this->putJson($this->c($this->a, 'access/'.$this->target), [])->assertNotFound();
        $this->putJson($this->c($this->b, 'access/'.$this->target), [])->assertUnprocessable()->assertJsonValidationErrors(['version', 'reason', 'permissions']);

        $this->postJson($this->c($this->a, 'access/'.$this->membership.'/revoke-membership'), [])->assertForbidden()
            ->assertJsonPath('message', 'You cannot remove yourself from the organization.');
        $this->postJson($this->c($this->a, 'access/'.Str::uuid().'/revoke-membership'), [])->assertNotFound();
        $this->postJson($this->c($this->a, 'access/'.$this->target.'/revoke-membership'), [])->assertNotFound();
        $this->postJson($this->c($this->b, 'access/'.$this->target.'/revoke-membership'), [])->assertUnprocessable()->assertJsonValidationErrors(['version', 'reason']);
        $this->assertSame(0, DB::connection('fixture')->table('audit_events')->count());
    }

    public function test_unknown_or_foreign_invitation_is_404_before_validation(): void
    {
        $foreign = $this->postJson($this->c($this->b, 'invitations'), ['email' => 'new@example.test', 'permissions' => ['company.read'], 'reason' => 'Setup'])->assertCreated()->json('data.id');
        foreach (['resend', 'cancel'] as $action) {
            $this->postJson($this->c($this->a, 'invitations/'.Str::uuid()."/$action"), [])->assertNotFound();
            $this->postJson($this->c($this->a, "invitations/$foreign/$action"), [])->assertNotFound();
            $this->postJson($this->c($this->b, "invitations/$foreign/$action"), [])->assertUnprocessable()->assertJsonValidationErrors(['version', 'reason']);
        }
    }

    public function test_invitation_links_check_origin_and_selector_before_validating_a_new_account(): void
    {
        $forged = ['tenant' => $this->t1, 'invitation' => (string) Str::uuid(), 'token' => str_repeat('a', 43)];
        $this->withHeader('Origin', 'https://evil.example')->postJson('/api/v1/invitations/accept', ['tenant' => 'x'])->assertForbidden()
            ->assertExactJson(['message' => 'Open the invitation link in the application.']);
        $this->withHeader('Origin', 'http://localhost');
        $this->postJson('/api/v1/invitations/accept', ['tenant' => 'x'])->assertNotFound()->assertExactJson(['message' => 'This invitation is invalid or has expired.']);
        // Well-formed but unknown: the definer lookup's 404 comes before the missing name and password.
        $this->postJson('/api/v1/invitations/accept', $forged)->assertNotFound()->assertExactJson(['message' => 'This invitation is invalid or has expired.']);
        $this->postJson('/api/v1/invitations/preview', $forged)->assertNotFound()->assertHeader('Cache-Control');
    }
}
