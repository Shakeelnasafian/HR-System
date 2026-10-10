<?php
namespace Tests\Feature;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class FoundationTest extends \Tests\Support\FoundationFixture
{
    public function test_runtime_role_is_not_owner_superuser_or_bypassrls(): void
    {
        $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');
        $this->assertFalse($role->rolsuper); $this->assertFalse($role->rolbypassrls);
        $this->assertNotSame(DB::selectOne('select current_user as name')->name, DB::selectOne("select pg_get_userbyid(relowner) as name from pg_class where relname = 'companies'")->name);
    }
    public function test_missing_context_fails_closed_for_raw_reads_and_writes(): void
    {
        $this->assertSame(0, DB::table('companies')->count());
        $this->assertSame(0, DB::table('companies')->where('id',$this->a)->update(['name'=>'Bad']));
        $this->assertSame(0, DB::table('companies')->where('id',$this->a)->delete());
        $this->expectException(QueryException::class);
        DB::table('companies')->insert(['id'=>(string) Str::uuid(),'tenant_id'=>$this->t1,'name'=>'Bad','code'=>'BAD','timezone'=>'UTC']);
    }
    public function test_context_switches_and_rollbacks_do_not_leak(): void
    {
        $context=app(TenantContext::class);
        $context->run($this->t1,$this->uid,fn()=> $this->assertSame(2,DB::table('companies')->count()));
        $context->run($this->t2,$this->uid,fn()=> $this->assertSame(1,DB::table('companies')->count()));
        try { $context->run($this->t1,$this->uid,function(){DB::table('companies')->where('id',$this->a)->update(['name'=>'Rollback']);throw new \RuntimeException('rollback');}); }
        catch (\RuntimeException $e) { $this->assertSame('rollback',$e->getMessage()); }
        $this->assertSame(0, DB::table('companies')->count());
        $this->assertSame('Allowed 1',DB::connection('fixture')->table('companies')->where('id',$this->a)->value('name'));
        $this->assertContains(DB::selectOne("select current_setting('app.tenant_id',true) as tenant")->tenant,[null,'']);
    }
    public function test_rls_blocks_raw_cross_tenant_read_and_owner_change(): void
    {
        app(TenantContext::class)->run($this->t1,$this->uid,function(){
            $this->assertNull(DB::table('companies')->where('id',$this->other)->first());
            $this->assertSame(0,DB::table('companies')->where('id',$this->other)->delete());
        });
        $this->expectException(QueryException::class);
        app(TenantContext::class)->run($this->t1,$this->uid,fn()=>DB::table('companies')->where('id',$this->a)->update(['tenant_id'=>$this->t2]));
    }
    public function test_composite_relationship_rejects_foreign_company(): void
    {
        $this->expectException(QueryException::class);
        app(TenantContext::class)->run($this->t1,$this->uid,fn()=>DB::table('company_grants')->insert(['tenant_id'=>$this->t1,'membership_id'=>$this->membership,'company_id'=>$this->other,'permission'=>'company.read']));
    }
    public function test_company_queries_enforce_grant_scope_and_hide_foreign_ids(): void
    {
        $this->signIn()->withHeader('X-Tenant-ID',$this->t1)->getJson('/api/v1/companies')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$this->a)->assertJsonMissing(['name'=>'Hidden company']);
        $this->getJson('/api/v1/companies/'.$this->b)->assertNotFound();
        $this->getJson('/api/v1/companies/'.$this->other)->assertNotFound();
        $this->getJson('/api/v1/companies/not-a-uuid')->assertNotFound();
        $this->withHeader('X-Tenant-ID',$this->t2)->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.tenant_id',$this->t2)->assertJsonPath('data.companies.0.id',$this->other);
        $this->withHeader('X-Tenant-ID',$this->t1)->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.tenant_id',$this->t1);
    }
    public function test_guest_missing_context_and_revoked_access_are_denied(): void
    {
        $this->getJson('/api/v1/companies')->assertUnauthorized();
        $this->signIn()->getJson('/api/v1/companies')->assertStatus(400);
        $this->withHeader('X-Tenant-ID',$this->t1)->getJson('/api/v1/companies?tenant_id='.$this->t2)->assertUnprocessable();
        DB::connection('fixture')->table('tenant_memberships')->where('id',$this->membership)->update(['status'=>'suspended']);
        $this->getJson('/api/v1/companies')->assertForbidden();
        $this->getJson('/api/v1/me/tenants')->assertOk()->assertJsonCount(1,'data');
    }
    public function test_suspended_tenant_blocks_requests(): void
    {
        DB::connection('fixture')->table('tenants')->where('id',$this->t1)->update(['status'=>'suspended']);
        $this->signIn()->withHeader('X-Tenant-ID',$this->t1)->getJson('/api/v1/context')->assertForbidden();
    }
    public function test_mfa_requires_enrollment_and_a_verified_session(): void
    {
        DB::connection('fixture')->table('tenant_memberships')->where('id',$this->membership)->update(['requires_mfa'=>true]);
        $this->signIn()->withHeader('X-Tenant-ID',$this->t1)->getJson('/api/v1/context')->assertForbidden();
        $user=User::find($this->uid); $user->forceFill(['two_factor_secret'=>encrypt('test'),'two_factor_confirmed_at'=>now()])->save();
        $this->actingAs($user)->getJson('/api/v1/context')->assertForbidden();
        $this->withSession(['mfa_user_id'=>$this->uid])->getJson('/api/v1/context')->assertOk();
    }
    public function test_public_registration_is_disabled_and_login_works(): void
    {
        $this->postJson('/register',[])->assertNotFound();
        $this->postJson('/login',['email'=>'member@example.test','password'=>'incorrect'])->assertUnprocessable();
        $this->postJson('/login',['email'=>'member@example.test','password'=>'test-password-123'])->assertOk();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.two_factor_secret');
        $this->postJson('/logout')->assertNoContent();
    }
    public function test_valid_authenticator_challenge_marks_session(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        User::find($this->uid)->forceFill(['two_factor_secret'=>encrypt($secret),'two_factor_recovery_codes'=>encrypt(json_encode(['recovery-test'])),'two_factor_confirmed_at'=>now()])->save();
        $this->postJson('/login',['email'=>'member@example.test','password'=>'test-password-123'])->assertOk()->assertJsonPath('two_factor',true);
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $code=(new \PragmaRX\Google2FA\Google2FA)->getCurrentOtp($secret);
        $this->postJson('/two-factor-challenge',['code'=>$code])->assertNoContent();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.mfa_verified',true);
    }
    public function test_runtime_cannot_disable_rls(): void
    {
        $this->expectException(QueryException::class);
        DB::statement('ALTER TABLE companies DISABLE ROW LEVEL SECURITY');
    }
    public function test_worker_reuse_clears_context_after_failure_and_rechecks_membership(): void
    {
        $path=storage_path('logs/probe-'.Str::uuid().'.jsonl'); $queue='isolation-'.Str::uuid();
        foreach ([[$this->t1,false],[$this->t2,false],[$this->t1,true],[$this->t2,false]] as [$t,$fail]) {
            dispatch((new \Tests\Support\TenantProbe($t,$this->uid,$path,$fail))->onQueue($queue));
        }
        dispatch((new \Tests\Support\OutsideProbe($path))->onQueue($queue));
        try {
            $process = new \Symfony\Component\Process\Process([PHP_BINARY,'artisan','queue:work','redis','--queue='.$queue,'--stop-when-empty','--tries=1','--sleep=0'],base_path(),['APP_ENV'=>'testing']);
            $process->setTimeout(60); $process->mustRun();
            $rows=array_map(fn($line)=>json_decode($line,true),file($path,FILE_IGNORE_NEW_LINES));
            $this->assertSame([2,1,2,1,0],array_column($rows,'count'));
            $this->assertCount(1,array_unique(array_column($rows,'pid')));
            $this->assertSame(1,DB::table('failed_jobs')->count());
            dispatch((new \Tests\Support\TenantProbe($this->t1,$this->uid,$path,false))->onQueue($queue));
            DB::connection('fixture')->table('tenant_memberships')->where('id',$this->membership)->update(['status'=>'suspended']);
            $process->mustRun();
            $this->assertCount(5,file($path,FILE_IGNORE_NEW_LINES));
            $this->assertSame(2,DB::table('failed_jobs')->count());
        } finally { if(file_exists($path)){unlink($path);} }
    }
}
