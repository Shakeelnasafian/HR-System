<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\CompanyRequest;
use App\Models\Workforce\EmployeeProfile;
use Illuminate\Validation\Rule;

class UpdateProfileFieldsRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'company.manage';
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'reason' => 'required|string|max:500', 'enabled' => 'present|array', 'enabled.*' => ['required', 'string', 'distinct', Rule::in(EmployeeProfile::FIELDS)]];
    }
}
