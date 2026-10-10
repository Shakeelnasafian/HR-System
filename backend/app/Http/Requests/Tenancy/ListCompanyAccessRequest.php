<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\CompanyRequest;
use App\Http\Requests\Concerns\Paginates;

class ListCompanyAccessRequest extends CompanyRequest
{
    use Paginates;

    protected function permission(): string
    {
        return 'access.manage';
    }

    public function rules(): array
    {
        return $this->paginationRules();
    }
}
