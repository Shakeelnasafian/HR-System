<?php

use App\Http\Controllers\Api\Account\CurrentUserController;
use App\Http\Controllers\Api\Account\TimezoneController;
use App\Http\Controllers\Api\Organization\CalendarController;
use App\Http\Controllers\Api\Organization\CalendarHolidayController;
use App\Http\Controllers\Api\Organization\CalendarPatternController;
use App\Http\Controllers\Api\Organization\CompanySettingsController;
use App\Http\Controllers\Api\Organization\OrganizationUnitController;
use App\Http\Controllers\Api\Organization\ProfileFieldSettingsController;
use App\Http\Controllers\Api\Tenancy\ContextController;
use App\Tenancy\AccessController;
use App\Tenancy\InvitationAcceptance;
use App\Tenancy\InvitationController;
use App\Tenancy\PermissionBundleController;
use App\Workforce\AssignmentController;
use App\Workforce\ProfileController;
use App\Workforce\WorkforceController;
use Illuminate\Support\Facades\Route;

/*
 * /api/v1 route declarations only. URL versioning is independent of the controller folders.
 * Company routes take {company} and child ids as plain strings: requests authorize the company first (404 when it is
 * unknown, foreign or not permitted), then resolve children inside it. Do not add implicit binding for tenant models.
 */

// Public invitation endpoints (SPA session and CSRF, rate limited by IP).
Route::prefix('v1/invitations')->middleware('throttle:invitations')->controller(InvitationAcceptance::class)->group(function () {
    Route::post('preview', 'preview');
    Route::post('accept', 'accept');
});

Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    // Account: no tenant context.
    Route::get('me', [CurrentUserController::class, 'show']);
    Route::get('me/tenants', [CurrentUserController::class, 'tenants']);
    Route::get('timezones', TimezoneController::class);

    // Everything below runs in one tenant transaction (X-Tenant-ID) with RLS and MFA enforcement.
    Route::middleware('tenant')->group(function () {
        Route::get('context', [ContextController::class, 'show']);
        Route::get('companies', [ContextController::class, 'companies']);

        Route::prefix('companies/{company}')->group(function () {
            // Tenancy: capabilities, permission bundles, company access, invitations.
            Route::get('capabilities', [WorkforceController::class, 'capabilities']);
            Route::get('permission-bundles', [PermissionBundleController::class, 'index']);
            Route::post('permission-bundles', [PermissionBundleController::class, 'store']);
            Route::post('permission-bundles/{bundle}/archive', [PermissionBundleController::class, 'archive']);
            Route::get('access', [AccessController::class, 'index']);
            Route::put('access/{membership}', [AccessController::class, 'replace']);
            Route::post('access/{membership}/revoke-membership', [AccessController::class, 'revokeMembership']);
            Route::get('invitations', [InvitationController::class, 'index']);
            Route::post('invitations', [InvitationController::class, 'store']);
            Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend']);
            Route::post('invitations/{invitation}/cancel', [InvitationController::class, 'cancel']);

            // Organization: company settings, organization units, calendars, profile field settings.
            Route::get('', [CompanySettingsController::class, 'show']);
            Route::patch('', [CompanySettingsController::class, 'update']);
            Route::get('organization/{kind}', [OrganizationUnitController::class, 'index']);
            Route::post('organization/{kind}', [OrganizationUnitController::class, 'store']);
            Route::patch('organization/{kind}/{id}', [OrganizationUnitController::class, 'update']);
            Route::get('calendars', [CalendarController::class, 'index']);
            Route::post('calendars', [CalendarController::class, 'store']);
            Route::get('calendars/{calendar}', [CalendarController::class, 'show']);
            Route::patch('calendars/{calendar}', [CalendarController::class, 'update']);
            Route::post('calendars/{calendar}/patterns', [CalendarPatternController::class, 'store']);
            Route::post('calendars/{calendar}/holidays', [CalendarHolidayController::class, 'store']);
            Route::delete('calendars/{calendar}/holidays/{holiday}', [CalendarHolidayController::class, 'destroy']);
            Route::get('profile-fields', [ProfileFieldSettingsController::class, 'show']);
            Route::put('profile-fields', [ProfileFieldSettingsController::class, 'update']);

            // Workforce: employees, employments, assignments, reporting lines, private profiles.
            Route::get('employees', [WorkforceController::class, 'employees']);
            Route::post('employees', [WorkforceController::class, 'createEmployee']);
            Route::get('employees/{id}', [WorkforceController::class, 'employee']);
            Route::post('employees/{id}/employments', [WorkforceController::class, 'rehire']);
            Route::get('employees/{employee}/profile', [ProfileController::class, 'show']);
            Route::patch('employees/{employee}/profile', [ProfileController::class, 'update']);
            Route::patch('employments/{employment}', [AssignmentController::class, 'update']);
            Route::get('employments/{employment}/assignments', [AssignmentController::class, 'index']);
            Route::post('employments/{employment}/assignments', [AssignmentController::class, 'store']);
            Route::get('employments/{employment}/reports', [AssignmentController::class, 'reports']);
            Route::post('employments/{id}/{action}', [WorkforceController::class, 'transition'])->whereIn('action', ['activate', 'end', 'cancel']);

            // Audit.
            Route::get('audit', [WorkforceController::class, 'audit']);
        });
    });
});
