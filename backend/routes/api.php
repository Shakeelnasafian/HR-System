<?php
use App\Tenancy\CompanyAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/me', fn (Request $r) => response()->json(['data' => [
        'id' => $r->user()->id, 'name' => $r->user()->name, 'email' => $r->user()->email,
        'mfa_enrolled' => (bool) $r->user()->two_factor_confirmed_at,
        'mfa_verified' => (int) $r->session()->get('mfa_user_id') === (int) $r->user()->id,
    ]])->header('Cache-Control', 'no-store, private'));
    Route::get('/me/tenants', fn (Request $r) => response()->json(['data' => DB::table('tenant_memberships as m')
        ->join('tenants as t', 't.id', '=', 'm.tenant_id')->where('m.user_id', $r->user()->id)
        ->where('m.status', 'active')->where('t.status', 'active')->orderBy('t.name')
        ->get(['t.id', 't.name', 'm.requires_mfa'])])->header('Cache-Control', 'no-store, private'));
    Route::middleware('tenant')->group(function () {
        Route::prefix('companies/{company}')->controller(\App\Workforce\WorkforceController::class)->group(function () {
            Route::get('capabilities','capabilities');
            Route::get('organization/{kind}','organization');
            Route::post('organization/{kind}','createOrganization');
            Route::patch('organization/{kind}/{id}','updateOrganization');
            Route::get('employees','employees');
            Route::post('employees','createEmployee');
            Route::get('employees/{id}','employee');
            Route::post('employees/{id}/employments','rehire');
            Route::post('employments/{id}/{action}','transition');
            Route::get('audit','audit');
        });

        Route::get('/context', fn (CompanyAccess $access, TenantContext $context) => ['data' => [
            'tenant_id' => $context->id(), 'companies' => $access->readable()->orderBy('name')->get(['id', 'name', 'code']),
        ]]);
        Route::get('/companies', function (Request $r, CompanyAccess $access) {
            $r->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
            return \Illuminate\Http\Resources\Json\JsonResource::collection($access->readable()->orderBy('name')->orderBy('id')->paginate((int) $r->input('per_page', 25), ['id', 'name', 'code', 'timezone']));
        });
        Route::get('/companies/{id}', function (string $id, CompanyAccess $access) {
            abort_unless(\Illuminate\Support\Str::isUuid($id), 404);
            $company = $access->readable()->where('companies.id', $id)->first(['id', 'name', 'code', 'timezone']);
            abort_unless($company, 404);
            return ['data' => $company];
        });
    });
});
