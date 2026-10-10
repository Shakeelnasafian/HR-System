<?php
namespace Tests\Feature;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\FoundationFixture;

class PermissionBundleTest extends FoundationFixture
{
    private function ready(): void
    {
        foreach(['access.manage','workforce.read'] as $permission) {
            DB::connection('fixture')->table('company_grants')->insert(['tenant_id'=>$this->t1,'company_id'=>$this->a,'membership_id'=>$this->membership,'permission'=>$permission]);
        }
        User::findOrFail($this->uid)->forceFill(['two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()])->save();
        $this->signIn()->withSession(['mfa_user_id'=>$this->uid])->withHeader('X-Tenant-ID',$this->t1);
    }
    private function url(?string $company=null): string { return '/api/v1/companies/'.($company??$this->a).'/permission-bundles'; }
    private function create(array $extra=[])
    {
        return $this->postJson($this->url(),array_merge(['name'=>'Workforce reader','permissions'=>['company.read','workforce.read'],'reason'=>'Synthetic review'],$extra));
    }
    public function test_templates_are_scoped_audited_immutable_and_archive_without_changing_grants(): void
    {
        $this->ready();
        $before=DB::connection('fixture')->table('company_grants')->count();
        $id=$this->create()->assertCreated()->json('data.id');
        $this->getJson($this->url())->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.delegable',true);
        $this->create()->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->getJson($this->url($this->b))->assertNotFound();
        $this->postJson($this->url($this->b).'/'.$id.'/archive',['reason'=>'Foreign scope'])->assertNotFound();
        $this->patchJson($this->url().'/'.$id,['name'=>'Changed'])->assertNotFound();
        $this->postJson($this->url().'/'.$id.'/archive',['reason'=>'Retired'])->assertOk();
        $this->postJson($this->url().'/'.$id.'/archive',['reason'=>'Retry'])->assertOk();
        $this->getJson($this->url())->assertJsonCount(0,'data');
        $this->assertSame($before,DB::connection('fixture')->table('company_grants')->count());
        $this->assertSame(2,DB::connection('fixture')->table('audit_events')->count());
        $this->assertSame(0,DB::table('permission_bundles')->count()); // no tenant context
        app(TenantContext::class)->run($this->t2,$this->uid,function() {
            $this->assertSame(0,DB::table('permission_bundles')->count());
        });
    }
    public function test_current_authority_and_mfa_are_required_for_every_operation(): void
    {
        $this->ready();
        $this->create(['permissions'=>['company.read','workforce.write']])->assertForbidden();
        $this->create(['permissions'=>['workforce.read']])->assertUnprocessable();
        $this->create(['permissions'=>['company.read','unknown']])->assertUnprocessable();
        $this->create(['permissions'=>['company.read','company.read']])->assertUnprocessable();
        $this->create(['name'=>'   '])->assertUnprocessable();
        $id=$this->create()->assertCreated()->json('data.id');
        DB::connection('fixture')->table('company_grants')->where('membership_id',$this->membership)->where('permission','workforce.read')->delete();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.0.delegable',false);
        $this->postJson($this->url().'/'.$id.'/archive',['reason'=>'Outside authority'])->assertForbidden();
        $this->withSession(['mfa_user_id'=>null])->getJson($this->url())->assertForbidden();
        $this->create(['name'=>'Other'])->assertForbidden();
        $this->withSession(['mfa_user_id'=>$this->uid]);
        DB::connection('fixture')->table('company_grants')->where('membership_id',$this->membership)->where('permission','access.manage')->delete();
        $this->getJson($this->url())->assertNotFound();
        $this->postJson($this->url().'/'.$id.'/archive',['reason'=>'Revoked'])->assertNotFound();
    }
    public function test_names_stay_reserved_after_archive_ignoring_case_and_whitespace(): void
    {
        $this->ready();
        $id=$this->create()->assertCreated()->json('data.id');
        $this->create(['name'=>'  WORKFORCE reader '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($this->url().'/'.$id.'/archive',['reason'=>'Retired'])->assertOk();
        $this->create()->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->create(['name'=>'workforce READER'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->create(['name'=>'Workforce reader v2'])->assertCreated();
        $this->assertSame(2,DB::connection('fixture')->table('permission_bundles')->count());
        // The database enforces the same rule for concurrent or direct writes.
        $this->expectException(QueryException::class);
        DB::connection('fixture')->table('permission_bundles')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$this->t1,'company_id'=>$this->a,'name'=>'WORKFORCE READER','permissions'=>'["company.read"]']);
    }
    public function test_permissions_must_be_a_nonempty_array_in_the_database(): void
    {
        foreach(['[]','{"company.read":true}'] as $permissions) {
            try {
                DB::connection('fixture')->table('permission_bundles')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$this->t1,'company_id'=>$this->a,'name'=>'Bad '.$permissions,'permissions'=>$permissions]);
                $this->fail('Invalid permissions were stored.');
            } catch(QueryException $e) { $this->assertStringContainsString('permission_bundles_permissions_nonempty',$e->getMessage()); }
        }
    }
    public function test_active_bundle_cap_and_company_access_validation(): void
    {
        $this->ready();
        $this->create(['permissions'=>['workforce.read']])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $rows=[];
        for($i=0;$i<100;$i++) { $rows[]=['id'=>(string)Str::uuid(),'tenant_id'=>$this->t1,'company_id'=>$this->a,'name'=>'Bundle '.$i,'permissions'=>'["company.read"]']; }
        DB::connection('fixture')->table('permission_bundles')->insert($rows);
        $this->create()->assertConflict();
        DB::connection('fixture')->table('permission_bundles')->where('name','Bundle 0')->update(['archived'=>true]);
        $this->create()->assertCreated();
        $this->assertSame(1,DB::connection('fixture')->table('audit_events')->count());
    }
    public function test_privileged_bundle_cannot_bypass_target_membership_mfa_policy(): void
    {
        $this->ready();
        $permissions=$this->create()->assertCreated()->json('data.permissions');
        $db=DB::connection('fixture');
        $user=$db->table('users')->insertGetId(['name'=>'Target Member','email'=>'target@example.test','password'=>Hash::make('synthetic-password')]);
        $target=(string)Str::uuid();
        $db->table('tenant_memberships')->insert(['id'=>$target,'tenant_id'=>$this->t1,'user_id'=>$user,'status'=>'active','requires_mfa'=>false]);
        $db->table('company_grants')->insert(['tenant_id'=>$this->t1,'membership_id'=>$target,'company_id'=>$this->a,'permission'=>'company.read']);
        $this->putJson('/api/v1/companies/'.$this->a.'/access/'.$target,['version'=>1,'permissions'=>$permissions,'reason'=>'Apply bundle'])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->assertSame(['company.read'],$db->table('company_grants')->where('membership_id',$target)->pluck('permission')->all());
        $this->assertSame(1,$db->table('companies')->where('id',$this->a)->value('access_version'));
    }
}
