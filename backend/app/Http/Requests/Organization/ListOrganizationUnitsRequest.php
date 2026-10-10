<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\Concerns\Paginates;

class ListOrganizationUnitsRequest extends OrganizationUnitRequest
{
    use Paginates;

    protected function permission(): string
    {
        return 'organization.read';
    }

    public function rules(): array
    {
        return $this->paginationRules();
    }
}
