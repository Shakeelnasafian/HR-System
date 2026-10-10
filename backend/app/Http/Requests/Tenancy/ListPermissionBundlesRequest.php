<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\CompanyRequest;

class ListPermissionBundlesRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'access.manage';
    }
}
