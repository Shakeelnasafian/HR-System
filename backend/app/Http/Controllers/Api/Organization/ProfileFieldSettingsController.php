<?php

namespace App\Http\Controllers\Api\Organization;

use App\Actions\Organization\ConfigureProfileFields;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\ShowProfileFieldsRequest;
use App\Http\Requests\Organization\UpdateProfileFieldsRequest;
use App\Http\Resources\Organization\ProfileFieldSettingsResource;

/** Which private-profile fields the company collects (the profiles themselves are in the Workforce module). */
class ProfileFieldSettingsController extends Controller
{
    public function show(ShowProfileFieldsRequest $request): ProfileFieldSettingsResource
    {
        return new ProfileFieldSettingsResource($request->company());
    }

    public function update(UpdateProfileFieldsRequest $request, ConfigureProfileFields $action): ProfileFieldSettingsResource
    {
        return new ProfileFieldSettingsResource($action->handle($request->company(lock: true), $request->validated()));
    }
}
