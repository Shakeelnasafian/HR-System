<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\CompanyRequest;

class ShowProfileFieldsRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'workforce.read';
    }
}
