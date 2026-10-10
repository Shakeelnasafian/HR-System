<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\Concerns\Paginates;
use Illuminate\Foundation\Http\FormRequest;

/** Listing is limited to readable companies by CompanyAccess, so there is no route company to authorize. */
class ListCompaniesRequest extends FormRequest
{
    use Paginates;

    public function rules(): array
    {
        return $this->paginationRules();
    }
}
