<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\CompanyRequest;

class ListCalendarsRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'organization.read';
    }

    public function rules(): array
    {
        return ['include_archived' => 'sometimes|boolean'];
    }
}
