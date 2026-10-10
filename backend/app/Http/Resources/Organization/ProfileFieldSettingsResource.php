<?php

namespace App\Http\Resources\Organization;

use App\Models\Tenancy\Company;
use App\Models\Workforce\EmployeeProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The company's enabled private-profile fields (stored order), every collectable field, and the settings version. @mixin Company */
class ProfileFieldSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['enabled' => $this->profile_fields, 'available' => EmployeeProfile::FIELDS, 'version' => $this->profile_fields_version];
    }
}
