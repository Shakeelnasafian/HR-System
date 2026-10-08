<?php
namespace Tests\Feature;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationFixture;

class WorkforceTest extends FoundationFixture
{
    private function grant(string $company, array $permissions): void
    {
        foreach($permissions as $permission) { DB::connection('fixture')->table('company_grants')->insert(['tenant_id'=>$this->t1,'company_id'=>$company,'membership_id'=>$this->membership,'permission'=>$permission]); }
    }
    private function ready(): void
    {
        $this->grant($this->a,['organization.read','organization.write','workforce.read','workforce.write','audit.read']);
        $this->signIn()->withHeader('X-Tenant-ID',$this->t1);
    }
    private function url(string $suffix, ?string $company=null): string { return '/api/v1/companies/'.($company??$this->a).'/'.$suffix; }
    private function person(array $extra=[]): array
    {
        return $this->postJson($this->url('employees'),$extra+['employee_number'=>'P001','legal_name'=>'Synthetic Person','employment_number'=>'E001','start_date'=>'2026-01-01'])->assertCreated()->json('data');
    }
    public function test_company_read_does_not_grant_hr_and_permissions_do_not_cross_companies(): void
    {
        $this->signIn()->withHeader('X-Tenant-ID',$this->t1);
        $this->getJson($this->url('employees'))->assertNotFound();
        $this->postJson($this->url('employees'),[])->assertNotFound();
        $this->grant($this->a,['workforce.read','workforce.write']);
        $this->grant($this->b,['company.read']);
        $person=$this->person();
        $this->getJson($this->url('employees'))->assertOk()->assertJsonCount(1,'data');
        $this->getJson($this->url('employees/'.$person['id'],$this->b))->assertNotFound();
        $this->postJson($this->url('employments/'.$person['employment_id'].'/activate',$this->b),['version'=>1,'reason'=>'Test'])->assertNotFound();
        $this->withHeader('X-Tenant-ID',$this->t2)->getJson($this->url('employees/'.$person['id'],$this->other))->assertNotFound();
    }
    public function test_organization_archival_versions_and_invalid_parent_references(): void
    {
        $this->ready();
        $org=$this->postJson($this->url('organization/departments'),['code'=>'eng','name'=>'Engineering'])->assertCreated()->json('data');
        $this->assertSame('ENG',$org['code']);
        $this->patchJson($this->url('organization/departments/'.$org['id']),['version'=>1,'archived'=>true])->assertOk()->assertJsonPath('data.version',2);
        $this->patchJson($this->url('organization/departments/'.$org['id']),['version'=>1,'name'=>'Stale'])->assertConflict();
        $this->postJson($this->url('employees'),['employee_number'=>'P1','legal_name'=>'Person','employment_number'=>'E1','start_date'=>'2026-01-01','department_id'=>$org['id']])->assertUnprocessable();
        $this->grant($this->b,['organization.write']);
        $foreign=$this->postJson($this->url('organization/departments',$this->b),['code'=>'OTHER','name'=>'Hidden'])->assertCreated()->json('data.id');
        $this->postJson($this->url('employees'),['employee_number'=>'P1','legal_name'=>'Person','employment_number'=>'E1','start_date'=>'2026-01-01','department_id'=>$foreign])->assertUnprocessable();
        $this->assertSame(0,DB::connection('fixture')->table('employees')->count());
        $this->getJson($this->url('organization/arbitrary'))->assertNotFound();
    }
    public function test_employment_lifecycle_rehire_versions_and_history(): void
    {
        $this->ready();$person=$this->person();$id=$person['employment_id'];
        $this->postJson($this->url("employments/$id/activate"),['version'=>1,'reason'=>'Start approved'])->assertOk()->assertJsonPath('data.status','active')->assertJsonPath('data.version',2);
        $this->postJson($this->url("employments/$id/end"),['version'=>1,'reason'=>'Stale request','end_date'=>'2026-02-01'])->assertConflict();
        $this->postJson($this->url("employments/$id/end"),['version'=>2,'reason'=>'Invalid interval','end_date'=>'2026-01-01'])->assertUnprocessable();
        $this->postJson($this->url("employments/$id/end"),['version'=>2,'reason'=>'Relationship ended','end_date'=>'2026-02-01'])->assertOk();
        $rehire=$this->postJson($this->url('employees/'.$person['id'].'/employments'),['employment_number'=>'E002','start_date'=>'2026-02-01'])->assertCreated()->json('data.id');
        $this->postJson($this->url("employments/$rehire/activate"),['version'=>1,'reason'=>'Rehire approved'])->assertOk();
        $this->getJson($this->url('employees/'.$person['id']))->assertOk()->assertJsonCount(2,'data.employments')->assertJsonPath('data.employments.0.status','active')->assertJsonPath('data.employments.1.status','ended');
        $this->assertSame(1,DB::connection('fixture')->table('employees')->count());
        $this->getJson($this->url('audit'))->assertOk()->assertJsonCount(6,'data');
        $this->assertStringNotContainsString('Synthetic Person',DB::connection('fixture')->table('audit_events')->pluck('changes')->toJson());
    }
    public function test_overlapping_employment_and_invalid_transitions_are_blocked(): void
    {
        $this->ready();$p=$this->person();$id=$p['employment_id'];
        $this->postJson($this->url("employments/$id/activate"),['version'=>1,'reason'=>'Start'])->assertOk();
        $id2=$this->postJson($this->url('employees/'.$p['id'].'/employments'),['employment_number'=>'E002','start_date'=>'2026-03-01'])->assertCreated()->json('data.id');
        $this->postJson($this->url("employments/$id2/activate"),['version'=>1,'reason'=>'Overlap'])->assertConflict();
        $this->postJson($this->url("employments/$id/cancel"),['version'=>2,'reason'=>'Invalid'])->assertConflict();
        $this->postJson($this->url("employments/$id2/cancel"),['version'=>1,'reason'=>'Duplicate draft'])->assertOk();
        $this->postJson($this->url("employments/$id2/activate"),['version'=>2,'reason'=>'Invalid'])->assertConflict();
        $future=$this->postJson($this->url('employees/'.$p['id'].'/employments'),['employment_number'=>'E003','start_date'=>now()->addYear()->toDateString()])->assertCreated()->json('data.id');
        $this->postJson($this->url("employments/$future/activate"),['version'=>1,'reason'=>'Too early'])->assertUnprocessable();
    }
    public function test_scoped_search_revocation_and_audit_scope(): void
    {
        $this->ready();$p=$this->person();
        $this->getJson($this->url('employees?q=Synthetic'))->assertOk()->assertJsonCount(1,'data')->assertJsonMissingPath('data.0.password');
        $this->getJson($this->url('employees?q=Nobody'))->assertOk()->assertJsonCount(0,'data');
        $this->getJson($this->url('employees?per_page=101'))->assertUnprocessable();
        $this->getJson($this->url('audit',$this->b))->assertNotFound();
        DB::connection('fixture')->table('company_grants')->where('permission','workforce.read')->delete();
        $this->getJson($this->url('employees/'.$p['id']))->assertNotFound();
    }
    public function test_audit_cannot_be_edited_by_runtime(): void
    {
        $this->ready();$this->person();
        $this->expectException(QueryException::class);
        app(TenantContext::class)->run($this->t1,$this->uid,fn()=>DB::table('audit_events')->update(['action'=>'forged']));
    }
    public function test_new_tables_fail_closed_without_context_and_enforce_company_foreign_keys(): void
    {
        $this->ready();$p=$this->person();
        foreach(['employees','employments','audit_events','departments','locations','positions'] as $table) {$this->assertSame(0,DB::table($table)->count());}
        $foreign=(string)Str::uuid();
        DB::connection('fixture')->table('departments')->insert(['id'=>$foreign,'tenant_id'=>$this->t1,'company_id'=>$this->b,'code'=>'HIDDEN','name'=>'Hidden']);
        $this->expectException(QueryException::class);
        app(TenantContext::class)->run($this->t1,$this->uid,fn()=>DB::table('employments')->where('id',$p['employment_id'])->update(['department_id'=>$foreign]));
    }
    public function test_duplicate_identity_rolls_back_without_duplicate_employment_or_audit(): void
    {
        $this->ready();$this->person();
        $this->postJson($this->url('employees'),['employee_number'=>'p001','legal_name'=>'Duplicate','employment_number'=>'E002','start_date'=>'2026-01-01'])->assertUnprocessable();
        $this->assertSame(1,DB::connection('fixture')->table('employees')->count());
        $this->assertSame(1,DB::connection('fixture')->table('employments')->count());
        $this->assertSame(2,DB::connection('fixture')->table('audit_events')->count());
    }

    public function test_concurrent_activation_serializes_across_real_connections(): void
    {
        $this->ready(); $p=$this->person();
        $second=$this->postJson($this->url('employees/'.$p['id'].'/employments'),['employment_number'=>'E002','start_date'=>'2026-02-01'])->assertCreated()->json('data.id');
        $barrier=storage_path('logs/activation-'.Str::uuid()); $processes=[];
        try {
            foreach([$p['employment_id'],$second] as $id) {
                $process=new \Symfony\Component\Process\Process([PHP_BINARY,'tests/Support/activate.php',$this->t1,(string)$this->uid,$this->a,$id,$barrier],base_path(),['APP_ENV'=>'testing']);
                $process->setTimeout(25); $process->start(); $processes[]=$process;
            }
            $deadline=microtime(true)+15;
            while(count(glob($barrier.'.*'))<2 && microtime(true)<$deadline) {usleep(10000);}
            $this->assertCount(2,glob($barrier.'.*'),'Both processes must reach the barrier.');
            file_put_contents($barrier,'go'); $statuses=[];
            foreach($processes as $process) { $process->wait(); $this->assertTrue($process->isSuccessful(),$process->getErrorOutput()); $statuses[]=trim($process->getOutput()); }
            sort($statuses); $this->assertSame(['200','409'],$statuses);
            $this->assertSame(1,DB::connection('fixture')->table('employments')->where('status','active')->count());
            $this->assertSame(1,DB::connection('fixture')->table('audit_events')->where('action','employment.activate')->count());
        } finally {
            foreach($processes as $process) { if($process->isRunning()) {$process->stop();} }
            foreach(glob($barrier.'*') as $file) {unlink($file);}
        }
    }
}
