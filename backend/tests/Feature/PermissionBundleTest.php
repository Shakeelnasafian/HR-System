<?php
namespace Tests\Feature;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
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
        $this->create()->assertConflict();
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
}
