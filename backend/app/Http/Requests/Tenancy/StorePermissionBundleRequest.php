<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\CompanyRequest;
use App\Services\Tenancy\PermissionCatalog;
use Illuminate\Validation\Rule;

class StorePermissionBundleRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'access.manage';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'reason' => ['required', 'string', 'max:500', 'regex:/\S/u'],
            'permissions' => 'required|array|min:1|max:'.count(PermissionCatalog::LABELS),
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(array_keys(PermissionCatalog::LABELS))],
        ];
    }
}
