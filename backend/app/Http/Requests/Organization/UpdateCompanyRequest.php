<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\CompanyRequest;
use DateTimeZone;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'company.manage';
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'reason' => 'required|string|max:500',
            'name' => 'sometimes|required|string|max:120', 'timezone' => ['sometimes', 'required', 'string', Rule::in(DateTimeZone::listIdentifiers())]];
    }
}
