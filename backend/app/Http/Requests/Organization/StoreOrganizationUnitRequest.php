<?php

namespace App\Http\Requests\Organization;

class StoreOrganizationUnitRequest extends OrganizationUnitRequest
{
    protected function permission(): string
    {
        return 'organization.write';
    }

    public function rules(): array
    {
        return ['code' => 'required|string|max:40|regex:/^[A-Za-z0-9_-]+$/', 'name' => 'required|string|max:160'];
    }
}
