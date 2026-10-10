<?php

namespace App\Http\Controllers\Api\Organization;

use App\Actions\Organization\UpdateCompanySettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\ShowCompanyRequest;
use App\Http\Requests\Organization\UpdateCompanyRequest;
use App\Http\Resources\Organization\CompanySettingsResource;

/** The route company's own settings: name and timezone (the code is immutable). */
class CompanySettingsController extends Controller
{
    public function show(ShowCompanyRequest $request): CompanySettingsResource
    {
        return new CompanySettingsResource($request->company());
    }

    public function update(UpdateCompanyRequest $request, UpdateCompanySettings $action): CompanySettingsResource
    {
        return new CompanySettingsResource($action->handle($request->company(lock: true), $request->validated()));
    }
}
