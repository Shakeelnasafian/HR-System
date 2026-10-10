<?php

namespace Tests\Feature;

use App\Models\Tenancy\Company;
use App\Models\Workforce\Employee;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\Support\FoundationFixture;

class TenantRoutingTest extends FoundationFixture
{
    public function test_route_model_binding_runs_inside_the_tenant_context(): void
    {
        // Implicit binding of a tenant model fails closed without a context, so this only resolves if the tenant
        // middleware (transaction, RLS setting, principal) runs before SubstituteBindings.
        Route::middleware(['api', 'auth:sanctum', 'tenant'])->get('/api/test-binding/{company}', fn (Company $company) => ['id' => $company->id]);
        $this->signIn()->withHeader('X-Tenant-ID', $this->t1);
        $this->getJson('/api/test-binding/'.$this->a)->assertOk()->assertExactJson(['id' => $this->a]);
        // RLS and the tenant scope both hide another tenant's company.
        $this->getJson('/api/test-binding/'.$this->other)->assertNotFound();
    }

    public function test_tenant_models_fail_closed_outside_a_tenant_context(): void
    {
        $this->expectException(LogicException::class);
        Employee::query()->count();
    }

    public function test_tenant_models_are_limited_to_the_context_tenant(): void
    {
        $ids = app(TenantContext::class)->run($this->t1, $this->uid, fn () => Company::query()->orderBy('code')->pluck('id')->all());
        $this->assertSame([$this->a, $this->b], $ids);
    }
}
