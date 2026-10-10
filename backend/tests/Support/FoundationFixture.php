<?php
namespace Tests\Support;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class FoundationFixture extends TestCase
{
    protected string $t1;
    protected string $t2;
    protected string $a;
    protected string $b;
    protected string $other;
    protected int $uid;
    protected string $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost');
        config(['database.connections.fixture' => array_merge(config('database.connections.pgsql'), [
            'username' => env('TEST_ADMIN_USERNAME', 'postgres'), 'password' => env('TEST_ADMIN_PASSWORD', 'postgres'),
        ])]);
        $db = DB::connection('fixture');
        $db->statement('TRUNCATE company_grants, companies, tenant_memberships, tenants, users, sessions, password_reset_tokens, failed_jobs RESTART IDENTITY CASCADE');
        $this->t1 = (string) Str::uuid(); $this->t2 = (string) Str::uuid();
        $this->a = (string) Str::uuid(); $this->b = (string) Str::uuid(); $this->other = (string) Str::uuid();
        $this->uid = $db->table('users')->insertGetId(['name'=>'Test Member','email'=>'member@example.test','password'=>Hash::make('test-password-123')]);
        foreach ([$this->t1, $this->t2] as $i => $id) {
            $db->table('tenants')->insert(['id'=>$id,'name'=>'Tenant '.($i+1),'status'=>'active']);
            $m = (string) Str::uuid();
            $db->table('tenant_memberships')->insert(['id'=>$m,'tenant_id'=>$id,'user_id'=>$this->uid,'status'=>'active','requires_mfa'=>false]);
            if ($i === 0) { $this->membership = $m; }
            $company = $i === 0 ? $this->a : $this->other;
            $db->table('companies')->insert(['id'=>$company,'tenant_id'=>$id,'name'=>'Allowed '.($i+1),'code'=>'ONE']);
            $db->table('company_grants')->insert(['tenant_id'=>$id,'membership_id'=>$m,'company_id'=>$company,'permission'=>'company.read']);
        }
        $db->table('companies')->insert(['id'=>$this->b,'tenant_id'=>$this->t1,'name'=>'Hidden company','code'=>'TWO']);
    }

    protected function signIn(): static
    {
        $this->actingAs(User::findOrFail($this->uid), 'web');
        return $this->withHeader('Origin', 'http://localhost');
    }

    /** Privileged grants need a membership that requires MFA (database trigger) and an MFA-verified session (CompanyAccess). */
    protected function requireMfa(): static
    {
        DB::connection('fixture')->table('tenant_memberships')->where('id', $this->membership)->update(['requires_mfa' => true]);
        $user = User::findOrFail($this->uid);
        $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
        auth()->forgetGuards(); // the sanctum guard caches the previously resolved user instance
        return $this->actingAs($user, 'web')->withSession(['mfa_user_id' => $this->uid]);
    }

}
