<?php
namespace Tests\Feature;

use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationFixture;

class AssignmentTest extends FoundationFixture
{
    private function grant(string $company, array $permissions): void
    {
        foreach($permissions as $permission) { DB::connection('fixture')->table('company_grants')->insert(['tenant_id'=>$this->t1,'company_id'=>$company,'membership_id'=>$this->membership,'permission'=>$permission]); }
    }
    private function ready(): void
    {
        $this->requireMfa(); $this->grant($this->a,['organization.read','organization.write','workforce.read','workforce.write']);
        $this->signIn()->withHeader('X-Tenant-ID',$this->t1);
    }
    private function url(string $suffix, ?string $company=null): string { return '/api/v1/companies/'.($company??$this->a).'/'.$suffix; }
    private function person(string $n, array $extra=[], ?string $company=null): array
    {
        return $this->postJson($this->url('employees',$company),$extra+['employee_number'=>'P'.$n,'legal_name'=>'Synthetic Person '.$n,'employment_number'=>'E'.$n,'start_date'=>'2026-01-01'])->assertCreated()->json('data');
    }
    private function org(string $kind, string $code, ?string $company=null): string
    {
        return $this->postJson($this->url("organization/$kind",$company),['code'=>$code,'name'=>"Name $code"])->assertCreated()->json('data.id');
    }
    private function calendar(string $code): string
    {
        return $this->postJson($this->url('calendars'),['code'=>$code,'name'=>"Calendar $code",'reason'=>'Setup','effective_from'=>'2026-01-01','working_days'=>[1,2,3,4,5]])->assertCreated()->json('data.id');
    }
    private function assign(string $employment, array $body, int $version=1)
    {
        return $this->postJson($this->url("employments/$employment/assignments"),$body+['version'=>$version,'reason'=>'Reorganisation']);
    }
    private function version(string $employment): int { return (int)DB::connection('fixture')->table('employments')->where('id',$employment)->value('version'); }

    public function test_creation_records_initial_assignment_and_changes_inherit_or_clear_fields(): void
    {
        $this->ready();
        $type=$this->org('employment_types','full_time');
        $this->getJson($this->url('organization/employment_types'))->assertOk()->assertJsonPath('data.0.code','FULL_TIME');
        [$d1,$d2,$loc,$pos,$cal]=[$this->org('departments','D1'),$this->org('departments','D2'),$this->org('locations','L1'),$this->org('positions','P1'),$this->calendar('STD')];
        $this->postJson($this->url('employees'),['employee_number'=>'PX','legal_name'=>'X','employment_number'=>'EX','start_date'=>'2026-01-01','probation_end_date'=>'2025-12-31'])->assertUnprocessable()->assertJsonValidationErrors('probation_end_date');
        $p=$this->person('1',['department_id'=>$d1,'location_id'=>$loc,'employment_type_id'=>$type,'calendar_id'=>$cal,'probation_end_date'=>'2026-06-30']); $e=$p['employment_id'];
        $this->getJson($this->url('employees/'.$p['id']))->assertOk()
            ->assertJsonPath('data.employments.0.probation_end_date','2026-06-30')
            ->assertJsonPath('data.employments.0.current_assignment.effective_from','2026-01-01')
            ->assertJsonPath('data.employments.0.current_assignment.department',['id'=>$d1,'code'=>'D1','name'=>'Name D1'])
            ->assertJsonPath('data.employments.0.current_assignment.employment_type.code','FULL_TIME')
            ->assertJsonPath('data.employments.0.current_assignment.calendar.name','Calendar STD')
            ->assertJsonPath('data.employments.0.current_assignment.position',null)
            ->assertJsonPath('data.employments.0.current_assignment.manager',null)
            ->assertJsonMissingPath('data.employments.0.department_id');
        $this->assign($e,['effective_from'=>'2026-03-01','position_id'=>$pos])->assertCreated()
            ->assertJsonPath('data.department.id',$d1)->assertJsonPath('data.position.id',$pos)->assertJsonPath('data.location.id',$loc)->assertJsonPath('data.employment_version',2);
        $this->assign($e,['effective_from'=>'2026-04-01','department_id'=>null],1)->assertConflict();
        $this->assign($e,['effective_from'=>'2026-04-01','department_id'=>null],2)->assertCreated()
            ->assertJsonPath('data.department',null)->assertJsonPath('data.position.id',$pos)->assertJsonPath('data.calendar.id',$cal);
        // A backdated row copies from the row in effect on its own date; later rows are unchanged.
        $this->assign($e,['effective_from'=>'2026-02-01','location_id'=>null,'department_id'=>$d2],3)->assertCreated()
            ->assertJsonPath('data.department.id',$d2)->assertJsonPath('data.position',null)->assertJsonPath('data.location',null)->assertJsonPath('data.employment_type.id',$type);
        $history=$this->getJson($this->url("employments/$e/assignments"))->assertOk()->json('data');
        $this->assertSame(['2026-04-01','2026-03-01','2026-02-01','2026-01-01'],array_column($history,'effective_from'));
        $this->assertSame([$loc,$loc,null,$loc],array_map(fn($a)=>$a['location']['id']??null,$history));
        $this->assertSame('Reorganisation',$history[0]['reason']);
        $audit=DB::connection('fixture')->table('audit_events')->where('action','employment.assignment_added')->orderBy('seq')->get();
        $this->assertCount(3,$audit);
        $this->assertSame(['department_id','location_id'],json_decode($audit[2]->changes,true)['fields']);
        $this->assertSame(['position_id'],json_decode($audit[0]->changes,true)['fields']);
        $this->getJson($this->url('employees/'.$p['id']))->assertOk()->assertJsonPath('data.employments.0.current_assignment.effective_from','2026-04-01')->assertJsonPath('data.employments.0.version',4);
    }
    public function test_effective_dates_references_and_states_are_validated(): void
    {
        $this->ready(); $p=$this->person('1'); $e=$p['employment_id'];
        $this->assign($e,['effective_from'=>'2025-12-31'])->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->assign($e,['effective_from'=>'2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->assign($e,['effective_from'=>'2026-13-01'])->assertUnprocessable();
        $this->postJson($this->url("employments/$e/assignments"),['version'=>1,'effective_from'=>'2026-02-01'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $archived=$this->org('departments','OLD'); $this->patchJson($this->url("organization/departments/$archived"),['version'=>1,'archived'=>true])->assertOk();
        $cal=$this->calendar('OLDCAL'); $this->patchJson($this->url("calendars/$cal"),['version'=>1,'reason'=>'Retired','archived'=>true])->assertOk();
        $this->grant($this->b,['organization.write','workforce.write']);
        $foreign=$this->org('departments','FOREIGN',$this->b); $foreignPerson=$this->person('9',[],$this->b);
        foreach([['department_id'=>$archived],['calendar_id'=>$cal],['department_id'=>$foreign],['employment_type_id'=>(string)Str::uuid()],['position_id'=>'not-a-uuid'],['manager_employment_id'=>$foreignPerson['employment_id']]] as $invalid) {
            $this->assign($e,['effective_from'=>'2026-02-01']+$invalid)->assertUnprocessable();
        }
        $this->assertSame(1,$this->version($e));
        $this->assign($foreignPerson['employment_id'],['effective_from'=>'2026-02-01'])->assertNotFound();
        $this->getJson($this->url('employments/'.$foreignPerson['employment_id'].'/assignments'))->assertNotFound();
        $this->getJson($this->url('employments/not-a-uuid/assignments'))->assertNotFound();
        // Ended employments accept history only inside [start, end); cancelled ones are frozen.
        $this->postJson($this->url("employments/$e/activate"),['version'=>1,'reason'=>'Start'])->assertOk();
        $this->postJson($this->url("employments/$e/end"),['version'=>2,'reason'=>'Left','end_date'=>'2026-05-01'])->assertOk();
        $this->assign($e,['effective_from'=>'2026-05-01'],3)->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->assign($e,['effective_from'=>'2026-04-30'],3)->assertCreated();
        $c=$this->person('2'); $this->postJson($this->url('employments/'.$c['employment_id'].'/cancel'),['version'=>1,'reason'=>'Mistake'])->assertOk();
        $this->assign($c['employment_id'],['effective_from'=>'2026-02-01'],2)->assertConflict();
        DB::connection('fixture')->table('company_grants')->where('company_id',$this->a)->where('permission','workforce.write')->delete();
        $this->assign($e,['effective_from'=>'2026-03-01'],4)->assertNotFound();
        $this->withHeader('X-Tenant-ID',$this->t2)->getJson($this->url("employments/$e/assignments",$this->other))->assertNotFound();
    }
    public function test_manager_rules_and_cycles_including_future_dated_chains(): void
    {
        $this->ready();
        [$a,$b,$c]=array_map(fn($n)=>$this->person($n)['employment_id'],['A','B','C']);
        $this->assign($b,['effective_from'=>'2026-02-01','manager_employment_id'=>$a])->assertCreated()->assertJsonPath('data.manager.employment_id',$a)->assertJsonPath('data.manager.legal_name','Synthetic Person A');
        $this->assign($c,['effective_from'=>'2026-02-01','manager_employment_id'=>$b])->assertCreated();
        $this->assign($a,['effective_from'=>'2026-03-01','manager_employment_id'=>$c])->assertUnprocessable()->assertJsonValidationErrors('manager_employment_id');
        // Before the chain exists on 2026-01-15 there is no cycle, but it would appear on 2026-02-01.
        $this->assign($a,['effective_from'=>'2026-01-15','manager_employment_id'=>$c])->assertUnprocessable()->assertJsonValidationErrors(['manager_employment_id'=>'2026-02-01']);
        $this->assign($a,['effective_from'=>'2026-01-15','manager_employment_id'=>$a])->assertUnprocessable();
        // A future-dated reporting line is part of the chain too.
        [$x,$y]=array_map(fn($n)=>$this->person($n)['employment_id'],['X','Y']);
        $this->assign($x,['effective_from'=>'2027-01-01','manager_employment_id'=>$y])->assertCreated();
        $this->assign($y,['effective_from'=>'2026-11-01','manager_employment_id'=>$x])->assertUnprocessable()->assertJsonValidationErrors(['manager_employment_id'=>'2027-01-01']);
        $this->assign($y,['effective_from'=>'2026-11-01','manager_employment_id'=>$a])->assertCreated();
        // Same person, unstarted or cancelled employments cannot manage.
        $p=$this->person('S'); $this->postJson($this->url('employees/'.$p['id'].'/employments'),['employment_number'=>'E-S2','start_date'=>'2026-06-01','manager_employment_id'=>$p['employment_id']])->assertUnprocessable();
        $late=$this->person('L',['start_date'=>'2026-06-01'])['employment_id'];
        $this->assign($a,['effective_from'=>'2026-03-01','manager_employment_id'=>$late])->assertUnprocessable();
        $this->assign($a,['effective_from'=>'2026-06-01','manager_employment_id'=>$late])->assertCreated();
        $gone=$this->person('G')['employment_id']; $this->postJson($this->url("employments/$gone/cancel"),['version'=>1,'reason'=>'Mistake'])->assertOk();
        $this->assign($b,['effective_from'=>'2026-07-01','manager_employment_id'=>$gone],2)->assertUnprocessable();
        // Creating an employment with a manager records it in the initial assignment.
        $d=$this->person('D',['manager_employment_id'=>$a])['employment_id'];
        $this->assertSame($a,DB::connection('fixture')->table('employment_assignments')->where('employment_id',$d)->value('manager_employment_id'));
        $this->assertSame(0,DB::connection('fixture')->table('employments')->where('employment_number','E-S2')->count());
    }
    public function test_direct_reports_and_current_assignment_use_the_company_date(): void
    {
        $this->ready();
        Carbon::setTestNow(Carbon::parse('2026-06-30 21:00:00','UTC')); // 2026-07-01 01:00 in Asia/Dubai
        try {
            $m=$this->person('M'); $pos=$this->org('positions','LEAD');
            $r1=$this->person('R1',['manager_employment_id'=>$m['employment_id']]);
            $r2=$this->person('R2');
            $this->assign($r2['employment_id'],['effective_from'=>'2026-07-01','manager_employment_id'=>$m['employment_id']])->assertCreated();
            $this->assign($m['employment_id'],['effective_from'=>'2026-07-01','position_id'=>$pos])->assertCreated();
            $r3=$this->person('R3',['manager_employment_id'=>$m['employment_id'],'start_date'=>'2026-08-01']); // not started yet
            $this->postJson($this->url('employments/'.$r1['employment_id'].'/cancel'),['version'=>1,'reason'=>'Mistake'])->assertOk();
            $r4=$this->person('R4',['manager_employment_id'=>$m['employment_id']]);
            $reports=$this->getJson($this->url('employments/'.$m['employment_id'].'/reports'))->assertOk()->json('data');
            $this->assertSame([$r2['employment_id'],$r4['employment_id']],array_column($reports,'employment_id'));
            $this->assertSame(['employment_id','employment_number','status','employee_id','employee_number','legal_name','preferred_name'],array_keys($reports[0]));
            $this->getJson($this->url('employees/'.$m['id']))->assertJsonPath('data.employments.0.current_assignment.effective_from','2026-07-01');
            $this->getJson($this->url('employees/'.$r3['id']))->assertJsonPath('data.employments.0.current_assignment.effective_from','2026-08-01'); // future draft shows its initial assignment
            DB::connection('fixture')->table('companies')->where('id',$this->a)->update(['timezone'=>'UTC']); // still 2026-06-30
            $this->getJson($this->url('employees/'.$m['id']))->assertJsonPath('data.employments.0.current_assignment.effective_from','2026-01-01');
            $this->getJson($this->url('employments/'.$m['employment_id'].'/reports'))->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.employment_id',$r4['employment_id']);
        } finally { Carbon::setTestNow(); }
    }
    public function test_probation_date_edit_is_versioned_validated_and_audited(): void
    {
        $this->ready(); $e=$this->person('1')['employment_id']; $url=$this->url("employments/$e");
        $this->patchJson($url,['version'=>1,'reason'=>'Probation'])->assertUnprocessable()->assertJsonValidationErrors('probation_end_date');
        $this->patchJson($url,['version'=>1,'reason'=>'Probation','probation_end_date'=>'2025-12-31'])->assertUnprocessable();
        $this->patchJson($url,['version'=>1,'probation_end_date'=>'2026-06-30'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson($url,['version'=>1,'reason'=>'Probation','probation_end_date'=>'2026-06-30','start_date'=>'2020-01-01'])->assertOk()
            ->assertJsonPath('data.probation_end_date','2026-06-30')->assertJsonPath('data.version',2)->assertJsonPath('data.start_date','2026-01-01')->assertJsonPath('data.current_assignment.effective_from','2026-01-01');
        $this->patchJson($url,['version'=>1,'reason'=>'Stale','probation_end_date'=>null])->assertConflict();
        $this->patchJson($url,['version'=>2,'reason'=>'Same','probation_end_date'=>'2026-06-30'])->assertOk()->assertJsonPath('data.version',2);
        $this->patchJson($url,['version'=>2,'reason'=>'Waived','probation_end_date'=>null])->assertOk()->assertJsonPath('data.probation_end_date',null)->assertJsonPath('data.version',3);
        $audit=DB::connection('fixture')->table('audit_events')->where('action','employment.updated')->get();
        $this->assertCount(2,$audit); $this->assertSame(['fields'=>['probation_end_date']],json_decode($audit[0]->changes,true));
        $this->patchJson($this->url("employments/$e",$this->b),['version'=>3,'reason'=>'Hidden','probation_end_date'=>null])->assertNotFound();
    }
    public function test_assignment_history_is_append_only_for_the_runtime_role(): void
    {
        foreach(['UPDATE','DELETE','TRUNCATE'] as $privilege) {
            $this->assertFalse(DB::selectOne('SELECT has_table_privilege(current_user, ?, ?) AS v',['employment_assignments',$privilege])->v,"employment_assignments must be append-only ($privilege)");
        }
        foreach(['DELETE','TRUNCATE'] as $privilege) { $this->assertFalse(DB::selectOne('SELECT has_table_privilege(current_user, ?, ?) AS v',['employee_profiles',$privilege])->v,"employee_profiles $privilege"); }
        $this->assertFalse(DB::selectOne("SELECT has_table_privilege(current_user, 'employment_types', 'DELETE') AS v")->v);
        $this->ready(); $e=$this->person('1')['employment_id'];
        $this->expectException(QueryException::class);
        app(TenantContext::class)->run($this->t1,$this->uid,fn()=>DB::table('employment_assignments')->where('employment_id',$e)->update(['effective_from'=>'2020-01-01']));
    }
    public function test_data_migration_copies_legacy_columns_into_initial_assignments_as_the_owner(): void
    {
        $db=DB::connection('fixture'); $migration=require database_path('migrations/2026_10_11_000001_create_profiles_and_assignments.php');
        $db->beginTransaction();
        try {
            $db->statement('ALTER TABLE employments ADD COLUMN department_id uuid, ADD COLUMN location_id uuid, ADD COLUMN position_id uuid'); // pre-I5a shape
            [$dept,$person]=[(string)Str::uuid(),(string)Str::uuid()]; $ids=[(string)Str::uuid(),(string)Str::uuid(),(string)Str::uuid()];
            $db->table('departments')->insert(['id'=>$dept,'tenant_id'=>$this->t1,'company_id'=>$this->a,'code'=>'LEGACY','name'=>'Legacy']);
            $db->table('employees')->insert(['id'=>$person,'tenant_id'=>$this->t1,'employee_number'=>'L1','legal_name'=>'Legacy Person']);
            $db->table('employments')->insert([
                ['id'=>$ids[0],'tenant_id'=>$this->t1,'company_id'=>$this->a,'employee_id'=>$person,'employment_number'=>'L1','start_date'=>'2025-01-01','end_date'=>'2025-06-01','status'=>'ended','department_id'=>$dept],
                ['id'=>$ids[1],'tenant_id'=>$this->t1,'company_id'=>$this->a,'employee_id'=>$person,'employment_number'=>'L2','start_date'=>'2025-06-01','end_date'=>null,'status'=>'active','department_id'=>null],
            ]);
            $db->table('employees')->insert(['id'=>$ids[2],'tenant_id'=>$this->t2,'employee_number'=>'T2','legal_name'=>'Other Tenant']);
            $db->table('employments')->insert(['id'=>$ids[2],'tenant_id'=>$this->t2,'company_id'=>$this->other,'employee_id'=>$ids[2],'employment_number'=>'T2','start_date'=>'2025-03-01','status'=>'draft']);
            // Run as the (non-superuser) table owner, which FORCE RLS would otherwise restrict to zero rows.
            $owner=$db->selectOne("SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'employments'")->tableowner;
            $db->statement('SET LOCAL ROLE "'.str_replace('"','""',$owner).'"');
            $this->assertSame(3,$migration->backfill($db));
            $this->assertSame(0,$migration->backfill($db)); // idempotent
            $this->assertSame([true,true],[(bool)$db->selectOne("SELECT relforcerowsecurity AS f FROM pg_class WHERE relname = 'employments'")->f,(bool)$db->selectOne("SELECT relforcerowsecurity AS f FROM pg_class WHERE relname = 'employment_assignments'")->f]);
            $db->statement('RESET ROLE');
            $rows=$db->table('employment_assignments')->whereIn('employment_id',$ids)->orderBy('effective_from')->get()->keyBy('employment_id');
            $this->assertCount(3,$rows);
            foreach($ids as $id) { $this->assertSame(implode('-',sscanf(md5('hr.initial_assignment:'.$id),'%8s%4s%4s%4s%12s')),$rows[$id]->id); }
            $this->assertSame([$dept,'2025-01-01'],[$rows[$ids[0]]->department_id,$rows[$ids[0]]->effective_from]);
            $this->assertSame([null,'2025-06-01'],[$rows[$ids[1]]->department_id,$rows[$ids[1]]->effective_from]);
            $this->assertSame([$this->t2,$this->other],[$rows[$ids[2]]->tenant_id,$rows[$ids[2]]->company_id]);
        } finally { $db->rollBack(); }
    }
    public function test_reporting_lock_serializes_a_concurrent_reverse_edge(): void
    {
        $this->ready(); [$x,$y]=array_map(fn($n)=>$this->person($n)['employment_id'],['X','Y']);
        // A second runtime connection plays the first request: it holds the company reporting lock with X -> Y written but uncommitted.
        config(['database.connections.holder'=>config('database.connections.pgsql')]);
        $holder=DB::connection('holder'); $holder->beginTransaction();
        $barrier=storage_path('logs/reporting-'.Str::uuid()); $process=null;
        try {
            $holder->select("SELECT set_config('app.tenant_id', ?, true)",[$this->t1]);
            $holder->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',['hr.reporting_line:'.$this->t1.':'.$this->a]);
            $holder->table('employment_assignments')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$this->t1,'company_id'=>$this->a,'employment_id'=>$x,'effective_from'=>'2026-03-01',
                'manager_employment_id'=>$y,'created_by'=>$this->uid,'reason'=>'In flight']);
            file_put_contents($barrier,'go');
            $process=new \Symfony\Component\Process\Process([PHP_BINARY,'tests/Support/assign.php',$this->t1,(string)$this->uid,$this->a,$y,$x,'2026-03-01',$barrier],base_path(),['APP_ENV'=>'testing']);
            $process->setTimeout(25); $process->start();
            // Without the lock the reverse edge would not wait, miss the uncommitted X -> Y and succeed with 201.
            $deadline=microtime(true)+15; $waiting=false;
            while(!$waiting && $process->isRunning() && microtime(true)<$deadline) {
                $waiting=DB::connection('fixture')->selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND wait_event = 'advisory'")->n>0;
                if(!$waiting) { usleep(20000); }
            }
            $this->assertTrue($waiting,'The reverse edge must wait for the reporting-line lock. Output: '.$process->getOutput().$process->getErrorOutput());
            $holder->commit();
            $process->wait(); $this->assertTrue($process->isSuccessful(),$process->getErrorOutput());
            $this->assertSame('422',trim($process->getOutput()));
            $this->assertSame([$x],DB::connection('fixture')->table('employment_assignments')->whereNotNull('manager_employment_id')->pluck('employment_id')->all());
            $this->assertSame(0,DB::connection('fixture')->table('audit_events')->where('action','employment.assignment_added')->count());
        } finally {
            if($holder->transactionLevel()>0) { $holder->rollBack(); }
            DB::purge('holder');
            if($process?->isRunning()) { $process->stop(); }
            foreach(glob($barrier.'*') as $file) { unlink($file); }
        }
    }
    public function test_cycles_are_detected_between_people_holding_several_employments(): void
    {
        $this->ready(); $p=$this->person('P'); $a1=$p['employment_id']; $b=$this->person('B')['employment_id'];
        $a2=$this->postJson($this->url('employees/'.$p['id'].'/employments'),['employment_number'=>'E-P2','start_date'=>'2026-01-01'])->assertCreated()->json('data.id');
        $this->postJson($this->url("employments/$a1/activate"),['version'=>1,'reason'=>'Start'])->assertOk();
        $this->assign($b,['effective_from'=>'2026-02-01','manager_employment_id'=>$a2])->assertCreated();
        $this->assign($a1,['effective_from'=>'2026-03-01','manager_employment_id'=>$b],2)->assertUnprocessable()->assertJsonValidationErrors(['manager_employment_id'=>'cycle']);
        $this->assign($b,['effective_from'=>'2026-01-15','manager_employment_id'=>null],2)->assertCreated(); // B unmanaged in January only
        // Reverse order: P (via A1) manages nobody yet; B -> A2 is already in place from February, so A1 -> B in January closes on 2026-02-01.
        $this->assign($a1,['effective_from'=>'2026-01-20','manager_employment_id'=>$b],2)->assertUnprocessable()->assertJsonValidationErrors(['manager_employment_id'=>'2026-02-01']);
        // Ended history does not block: after A1 ends, the rehired relationship can be managed by B again only if no live link closes the loop.
        $this->assertSame(0,DB::connection('fixture')->table('employment_assignments')->where('employment_id',$a1)->whereNotNull('manager_employment_id')->count());
    }
    public function test_copied_references_must_still_be_active(): void
    {
        $this->ready(); $dept=$this->org('departments','OLD'); $pos=$this->org('positions','NEW');
        $m=$this->person('M')['employment_id']; $e=$this->person('E',['department_id'=>$dept,'manager_employment_id'=>$m])['employment_id'];
        $this->patchJson($this->url("organization/departments/$dept"),['version'=>1,'archived'=>true])->assertOk();
        $this->postJson($this->url("employments/$m/cancel"),['version'=>1,'reason'=>'Mistake'])->assertOk();
        $response=$this->assign($e,['effective_from'=>'2026-03-01','position_id'=>$pos])->assertUnprocessable()->assertJsonValidationErrors(['department_id','manager_employment_id']);
        $this->assertStringContainsString('clear it explicitly',$response->json('errors.department_id.0'));
        $this->assign($e,['effective_from'=>'2026-03-01','position_id'=>$pos,'department_id'=>null,'manager_employment_id'=>null])->assertCreated()
            ->assertJsonPath('data.department',null)->assertJsonPath('data.manager',null)->assertJsonPath('data.position.id',$pos);
        $this->assertSame(2,$this->version($m)); // cancelled once; the rejected assignment left the manager untouched
    }
    public function test_cancelled_employment_probation_is_frozen(): void
    {
        $this->ready(); $e=$this->person('1')['employment_id'];
        $this->postJson($this->url("employments/$e/cancel"),['version'=>1,'reason'=>'Mistake'])->assertOk();
        $this->patchJson($this->url("employments/$e"),['version'=>2,'reason'=>'Probation','probation_end_date'=>'2026-06-30'])->assertConflict();
        $this->assertNull(DB::connection('fixture')->table('employments')->where('id',$e)->value('probation_end_date'));
    }
}
