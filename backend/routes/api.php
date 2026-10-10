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
    // The server's IANA list is the only set PATCH /companies/{company} accepts; browsers' Intl lists differ (aliases, missing zones).
    Route::get('/timezones', fn () => response()->json(['data' => DateTimeZone::listIdentifiers()])->header('Cache-Control', 'private, max-age=86400'));
    Route::middleware('tenant')->group(function () {
        Route::get('companies/{company}/permission-bundles', [\App\Tenancy\PermissionBundleController::class, 'index']);
        Route::post('companies/{company}/permission-bundles', [\App\Tenancy\PermissionBundleController::class, 'store']);
        Route::post('companies/{company}/permission-bundles/{bundle}/archive', [\App\Tenancy\PermissionBundleController::class, 'archive']);
        Route::get('companies/{company}/access', [\App\Tenancy\AccessController::class, 'index']);
        Route::put('companies/{company}/access/{membership}', [\App\Tenancy\AccessController::class, 'replace']);
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
        Route::get('companies/{company}', [\App\Organization\CompanySettingsController::class, 'show']);
        Route::patch('companies/{company}', [\App\Organization\CompanySettingsController::class, 'update']);
        Route::prefix('companies/{company}/calendars')->controller(\App\Organization\CalendarController::class)->group(function () {
            Route::get('','index');
            Route::post('','store');
            Route::get('{calendar}','show');
            Route::patch('{calendar}','update');
            Route::post('{calendar}/patterns','addPattern');
            Route::post('{calendar}/holidays','addHoliday');
            Route::delete('{calendar}/holidays/{holiday}','removeHoliday');
        });

        Route::get('/context', fn (CompanyAccess $access, TenantContext $context) => ['data' => [
            'tenant_id' => $context->id(), 'companies' => $access->readable()->orderBy('name')->get(['id', 'name', 'code']),
        ]]);
        Route::get('/companies', function (Request $r, CompanyAccess $access) {
            $r->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
            return \App\Http\Resources\ProjectedRow::collection($access->readable()->orderBy('name')->orderBy('id')->paginate((int) $r->input('per_page', 25), ['id', 'name', 'code', 'timezone']));
        });
    });
});
