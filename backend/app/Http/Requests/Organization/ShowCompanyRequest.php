<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\CompanyRequest;

class ShowCompanyRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'company.read';
    }
}
