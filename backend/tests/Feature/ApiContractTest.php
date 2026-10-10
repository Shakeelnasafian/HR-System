<?php

namespace Tests\Feature;

use App\Jobs\DeliverOutboxEvent;
use App\Mail\InvitationMail;
use App\Services\Messaging\OutboxDelivery;
use App\Services\Messaging\OutboxRelay;
use App\Services\Messaging\Handlers\InvitationSendHandler;
use App\Services\Tenancy\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use stdClass;
use Tests\Support\FoundationFixture;

/**
 * Response contract of every /api/v1 endpoint: status, Cache-Control and the JSON body with keys, value types, nulls,
 * empty objects, pagination envelopes and messages. Volatile values are normalized: UUIDs become stable aliases in order
 * of first appearance, timestamps become their format, long lists keep their first items. The snapshot was recorded
 * before the Laravel structure refactor; regenerate it only for an approved contract change (CONTRACT_UPDATE=1).
 */
class ApiContractTest extends FoundationFixture
{
    private const SNAPSHOT = __DIR__.'/../Contracts/api-v1.json';

    private const UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    private array $contract = [];

    private array $aliases = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['outbox.handlers' => ['invitation.send' => InvitationSendHandler::class], 'outbox.system_context' => true]);
        Mail::fake();
    }

    public function test_every_endpoint_keeps_its_recorded_contract(): void
    {
        $db = DB::connection('fixture');
        $this->requireMfa();
        foreach (array_keys(PermissionCatalog::LABELS) as $permission) {
            if ($permission !== 'company.read') {
                $db->table('company_grants')->insert(['tenant_id' => $this->t1, 'company_id' => $this->a, 'membership_id' => $this->membership, 'permission' => $permission]);
            }
        }
        [$colleague, $leaver] = array_map(function (string $name) use ($db) {
            $user = $db->table('users')->insertGetId(['name' => "Synthetic $name", 'email' => strtolower($name).'@example.test', 'password' => Hash::make(Str::random(32))]);
            $membership = (string) Str::uuid();
            $db->table('tenant_memberships')->insert(['id' => $membership, 'tenant_id' => $this->t1, 'user_id' => $user, 'status' => 'active', 'requires_mfa' => true]);
            $db->table('company_grants')->insert(['tenant_id' => $this->t1, 'company_id' => $this->a, 'membership_id' => $membership, 'permission' => 'company.read']);

            return $membership;
        }, ['Colleague', 'Leaver']);
        $c = '/api/v1/companies/'.$this->a;

        // Account (no tenant context).
        $this->record('GET /me', $this->getJson('/api/v1/me'));
        $this->record('GET /me/tenants', $this->getJson('/api/v1/me/tenants'));
        $this->record('GET /timezones', $this->getJson('/api/v1/timezones'));
        $this->record('GET /context without tenant', $this->getJson('/api/v1/context'));
        $this->withHeader('X-Tenant-ID', $this->t1);
        $this->record('GET /context', $this->getJson('/api/v1/context'));
        $this->record('GET /companies', $this->getJson('/api/v1/companies?per_page=1'));
        $this->record('GET /companies invalid', $this->getJson('/api/v1/companies?per_page=500'));

        // Company settings and capabilities.
        $this->record('GET company', $this->getJson($c));
        $this->record('GET hidden company', $this->getJson('/api/v1/companies/'.$this->b));
        $this->record('PATCH company', $this->patchJson($c, ['version' => 1, 'reason' => 'Rename', 'name' => 'Allowed One']));
        $this->record('PATCH company stale', $this->patchJson($c, ['version' => 1, 'reason' => 'Rename', 'name' => 'Allowed Two']));
        $this->record('PATCH company invalid', $this->patchJson($c, ['version' => 2, 'reason' => 'Nothing']));
        $this->record('GET capabilities', $this->getJson("$c/capabilities"));

        // Permission bundles and company access.
        $bundle = $this->record('POST bundle', $this->postJson("$c/permission-bundles", ['name' => 'Readers', 'reason' => 'Template', 'permissions' => ['company.read', 'workforce.read']]))->json('data.id');
        $this->record('POST bundle duplicate', $this->postJson("$c/permission-bundles", ['name' => 'readers', 'reason' => 'Template', 'permissions' => ['company.read']]));
        $this->record('GET bundles', $this->getJson("$c/permission-bundles"));
        $this->record('POST bundle archive', $this->postJson("$c/permission-bundles/$bundle/archive", ['reason' => 'Unused']));
        $access = $this->record('GET access', $this->getJson("$c/access?per_page=2"))->json('access_version');
        $this->record('PUT access', $this->putJson("$c/access/$colleague", ['version' => $access, 'reason' => 'Promotion', 'permissions' => ['company.read', 'workforce.read']]));
        $this->record('PUT access stale', $this->putJson("$c/access/$colleague", ['version' => $access, 'reason' => 'Promotion', 'permissions' => ['company.read']]));
        $this->record('PUT access self', $this->putJson("$c/access/{$this->membership}", ['version' => $access + 1, 'reason' => 'Self', 'permissions' => ['company.read']]));
        $this->record('POST revoke membership', $this->postJson("$c/access/$leaver/revoke-membership", ['version' => $access + 1, 'reason' => 'Left']));

        // Invitations, delivered through the outbox, then opened from the emailed link.
        $invitation = $this->record('POST invitation', $this->postJson("$c/invitations", ['email' => 'Invitee@Example.test', 'permissions' => ['company.read'], 'reason' => 'Onboarding']))->json('data.id');
        $this->record('POST invitation duplicate', $this->postJson("$c/invitations", ['email' => 'invitee@example.test', 'permissions' => ['company.read'], 'reason' => 'Onboarding']));
        $this->record('POST invitation resend', $this->postJson("$c/invitations/$invitation/resend", ['version' => 1, 'reason' => 'Lost']));
        $other = $this->postJson("$c/invitations", ['email' => 'second@example.test', 'permissions' => ['company.read'], 'reason' => 'Onboarding'])->json('data.id');
        $this->record('POST invitation cancel', $this->postJson("$c/invitations/$other/cancel", ['version' => 1, 'reason' => 'Mistake']));
        $this->record('GET invitations', $this->getJson("$c/invitations?status=all"));
        Queue::fake();
        app(OutboxRelay::class)->run();
        Queue::pushed(DeliverOutboxEvent::class)->each(fn ($job) => app(OutboxDelivery::class)->run($job->tenantId, $job->eventId, $job->attempt));
        $mail = Mail::sent(InvitationMail::class)->last();
        parse_str((string) parse_url($mail->url, PHP_URL_FRAGMENT), $link);
        $this->record('POST invitation preview', $this->postJson('/api/v1/invitations/preview', $link));
        $this->record('POST invitation preview forged', $this->postJson('/api/v1/invitations/preview', ['token' => str_repeat('A', 43)] + $link));
        $this->record('POST invitation accept invalid', $this->postJson('/api/v1/invitations/accept', $link + ['name' => 'New Person']));

        // Organization units.
        $department = $this->record('POST department', $this->postJson("$c/organization/departments", ['code' => 'ops', 'name' => 'Operations']))->json('data.id');
        $this->record('POST department duplicate', $this->postJson("$c/organization/departments", ['code' => 'OPS', 'name' => 'Operations']));
        $this->postJson("$c/organization/departments", ['code' => 'OLD', 'name' => 'Old']);
        $this->record('PATCH department', $this->patchJson("$c/organization/departments/$department", ['version' => 1, 'name' => 'Operations Group']));
        $this->record('GET departments', $this->getJson("$c/organization/departments?per_page=1"));
        $this->record('GET unknown kind', $this->getJson("$c/organization/teams"));
        $type = $this->postJson("$c/organization/employment_types", ['code' => 'FT', 'name' => 'Full time'])->json('data.id');
        $this->record('GET employment types', $this->getJson("$c/organization/employment_types"));

        // Calendars.
        $calendar = $this->record('POST calendar', $this->postJson("$c/calendars", ['code' => 'STD', 'name' => 'Standard', 'reason' => 'Setup', 'effective_from' => '2026-01-01', 'working_days' => [5, 1, 2, 3, 4]]))->json('data.id');
        $this->record('POST calendar invalid', $this->postJson("$c/calendars", ['code' => 'std', 'name' => 'Again', 'reason' => 'Setup', 'effective_from' => '2026-01-01', 'working_days' => [1, 1]]));
        $this->record('POST calendar pattern', $this->postJson("$c/calendars/$calendar/patterns", ['version' => 1, 'reason' => 'Six days', 'effective_from' => '2026-02-01', 'working_days' => [1, 2, 3, 4, 5, 6]]));
        $holiday = $this->record('POST calendar holiday', $this->postJson("$c/calendars/$calendar/holidays", ['version' => 2, 'reason' => 'Public', 'holiday_date' => '2026-12-02', 'name' => 'National Day']))->json('data.id');
        $this->postJson("$c/calendars/$calendar/holidays", ['version' => 3, 'reason' => 'Public', 'holiday_date' => '2026-12-03', 'name' => 'Second Day']);
        $this->record('DELETE calendar holiday', $this->deleteJson("$c/calendars/$calendar/holidays/$holiday", ['version' => 4, 'reason' => 'Moved']));
        $this->record('PATCH calendar', $this->patchJson("$c/calendars/$calendar", ['version' => 5, 'reason' => 'Rename', 'name' => 'Standard Week']));
        $this->record('PATCH calendar stale', $this->patchJson("$c/calendars/$calendar", ['version' => 5, 'reason' => 'Rename', 'name' => 'Other']));
        $this->record('GET calendars', $this->getJson("$c/calendars"));
        $this->record('GET calendar', $this->getJson("$c/calendars/$calendar?year=2026"));

        // Employees, employments, assignments and reporting lines.
        $this->record('POST employee invalid', $this->postJson("$c/employees", ['employee_number' => 'bad number']));
        $manager = $this->record('POST employee', $this->postJson("$c/employees", ['employee_number' => 'm1', 'legal_name' => 'Synthetic Manager', 'employment_number' => 'em1', 'start_date' => '2026-01-01',
            'department_id' => $department, 'employment_type_id' => $type, 'calendar_id' => $calendar, 'probation_end_date' => '2026-06-30']))->json('data');
        $report = $this->postJson("$c/employees", ['employee_number' => 'R1', 'legal_name' => 'Synthetic Report', 'preferred_name' => 'Rep', 'employment_number' => 'ER1', 'start_date' => '2026-01-01'])->json('data');
        $this->record('GET employees', $this->getJson("$c/employees?per_page=1"));
        $this->record('GET employees search', $this->getJson("$c/employees?q=rep"));
        $this->record('POST activate', $this->postJson("$c/employments/{$manager['employment_id']}/activate", ['version' => 1, 'reason' => 'Start']));
        $this->record('POST activate stale', $this->postJson("$c/employments/{$manager['employment_id']}/activate", ['version' => 1, 'reason' => 'Start']));
        $this->record('POST unknown transition', $this->postJson("$c/employments/{$manager['employment_id']}/suspend", ['version' => 2, 'reason' => 'No']));
        $this->record('POST assignment', $this->postJson("$c/employments/{$report['employment_id']}/assignments", ['version' => 1, 'reason' => 'Reorganisation', 'effective_from' => '2026-02-01', 'manager_employment_id' => $manager['employment_id']]));
        $this->record('POST assignment cycle', $this->postJson("$c/employments/{$manager['employment_id']}/assignments", ['version' => 2, 'reason' => 'Swap', 'effective_from' => '2026-03-01', 'manager_employment_id' => $report['employment_id']]));
        $this->record('GET assignments', $this->getJson("$c/employments/{$report['employment_id']}/assignments"));
        $this->record('GET reports', $this->getJson("$c/employments/{$manager['employment_id']}/reports"));
        $this->record('PATCH employment', $this->patchJson("$c/employments/{$manager['employment_id']}", ['version' => 2, 'reason' => 'Extend', 'probation_end_date' => '2026-07-31']));
        $this->record('POST end', $this->postJson("$c/employments/{$manager['employment_id']}/end", ['version' => 3, 'reason' => 'Leaver', 'end_date' => '2026-05-01']));
        $this->record('POST rehire', $this->postJson("$c/employees/{$manager['id']}/employments", ['employment_number' => 'em2', 'start_date' => '2026-06-01']));
        $rehire = $this->postJson("$c/employees/{$report['id']}/employments", ['employment_number' => 'ER2', 'start_date' => '2027-01-01'])->json('data.id');
        $this->record('POST cancel', $this->postJson("$c/employments/$rehire/cancel", ['version' => 1, 'reason' => 'Mistake']));
        $this->record('GET employee', $this->getJson("$c/employees/{$manager['id']}"));
        $this->record('GET employee unknown', $this->getJson("$c/employees/".Str::uuid()));

        // Private profile.
        $this->record('GET profile fields', $this->getJson("$c/profile-fields"));
        $this->record('PUT profile fields', $this->putJson("$c/profile-fields", ['version' => 1, 'reason' => 'Collect', 'enabled' => ['emergency_contacts', 'birth_date', 'address']]));
        $this->record('PATCH profile', $this->patchJson("$c/employees/{$report['id']}/profile", ['version' => 0, 'reason' => 'Onboarding',
            'fields' => ['birth_date' => '1990-05-17', 'emergency_contacts' => [['name' => 'Synthetic Contact', 'relationship' => 'Sibling', 'phone' => '555 0100']]]]));
        $this->record('PATCH profile foreign field', $this->patchJson("$c/employees/{$report['id']}/profile", ['version' => 1, 'reason' => 'Onboarding', 'fields' => ['nationality' => 'X']]));
        $this->record('GET profile', $this->getJson("$c/employees/{$report['id']}/profile"));
        $empty = $this->postJson("$c/employees", ['employee_number' => 'E3', 'legal_name' => 'Synthetic Empty', 'employment_number' => 'EE3', 'start_date' => '2026-01-01'])->json('data.id');
        $this->putJson("$c/profile-fields", ['version' => 2, 'reason' => 'Stop', 'enabled' => []]);
        $this->record('GET profile with no fields', $this->getJson("$c/employees/$empty/profile"));

        // Audit history.
        $this->record('GET audit', $this->getJson("$c/audit?per_page=3"));
        $this->record('GET audit page 2', $this->getJson("$c/audit?per_page=3&page=2"));

        // Public acceptance last: it creates a new account.
        $this->record('POST invitation accept', $this->postJson('/api/v1/invitations/accept', $link + ['name' => 'New Person', 'password' => 'a-long-synthetic-password', 'password_confirmation' => 'a-long-synthetic-password']));
        $this->record('POST invitation accept reused', $this->postJson('/api/v1/invitations/accept', $link + ['name' => 'New Person', 'password' => 'a-long-synthetic-password', 'password_confirmation' => 'a-long-synthetic-password']));

        $actual = json_encode($this->contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        if (getenv('CONTRACT_UPDATE') === '1') {
            @mkdir(dirname(self::SNAPSHOT), 0777, true);
            file_put_contents(self::SNAPSHOT, $actual);
        }
        $this->assertFileExists(self::SNAPSHOT, 'Record the contract with CONTRACT_UPDATE=1 before changing endpoints.');
        $this->assertSame(file_get_contents(self::SNAPSHOT), $actual);
    }

    private function record(string $label, TestResponse $response): TestResponse
    {
        $this->assertArrayNotHasKey($label, $this->contract);
        $body = json_decode((string) $response->getContent(), false, 64, JSON_THROW_ON_ERROR);
        $this->contract[$label] = ['status' => $response->getStatusCode(), 'cache_control' => $response->headers->get('Cache-Control'), 'body' => $this->normalize($body)];

        return $response;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $fields = (array) $value;
            ksort($fields);

            return (object) array_map(fn ($item) => $this->normalize($item), $fields);
        }
        if (is_array($value)) {
            $items = count($value) > 20 ? [...array_slice($value, 0, 3), '<more>'] : $value;

            return array_map(fn ($item) => $this->normalize($item), $items);
        }
        if (! is_string($value)) {
            return $value;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}(:\d{2})?)?$/', $value)) {
            return '<timestamp:sql>';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            return '<timestamp:iso8601>';
        }

        return preg_replace_callback(self::UUID, fn ($m) => '<uuid:'.($this->aliases[strtolower($m[0])] ??= count($this->aliases) + 1).'>', $value);
    }
}
