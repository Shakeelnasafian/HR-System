<?php

namespace App\Http\Controllers\Api\Workforce;

use App\Actions\Workforce\UpdateEmployeeProfile;
use App\Actions\Workforce\ViewEmployeeProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workforce\ShowEmployeeProfileRequest;
use App\Http\Requests\Workforce\UpdateEmployeeProfileRequest;
use App\Http\Resources\Workforce\EmployeeProfileResource;
use App\Http\Resources\Workforce\EmployeeProfileUpdateResource;

/**
 * Private profile (EMP-01, SEC-03/04): separately authorized (profile.read/profile.write) and limited to the company's
 * enabled field set. Reads are audited with field names only; values never enter audit or directory payloads.
 */
class EmployeeProfileController extends Controller
{
    public function show(ShowEmployeeProfileRequest $request, ViewEmployeeProfile $view, string $company, string $employee): EmployeeProfileResource
    {
        return new EmployeeProfileResource($view->handle($company, $request->employeeId()));
    }

    public function update(UpdateEmployeeProfileRequest $request, UpdateEmployeeProfile $update, string $company, string $employee): EmployeeProfileUpdateResource
    {
        return new EmployeeProfileUpdateResource($update->handle($request->company(), $request->employeeId(), $request->all()));
    }
}
