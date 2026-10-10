<?php

namespace App\Http\Requests\Workforce;

use App\Http\Requests\CompanyRequest;

class ShowEmployeeRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'workforce.read';
    }
}
