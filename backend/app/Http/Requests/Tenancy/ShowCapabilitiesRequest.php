<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\CompanyRequest;

class ShowCapabilitiesRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'company.read';
    }
}
