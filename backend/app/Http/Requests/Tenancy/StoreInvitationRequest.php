<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\CompanyRequest;
use App\Services\Tenancy\PermissionCatalog;
use Illuminate\Validation\Rule;

class StoreInvitationRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'access.manage';
    }

    public function rules(): array
    {
        return [
            'email' => 'required|string|email|max:254', 'reason' => ['required', 'string', 'max:500', 'regex:/\S/u'],
            'permissions' => 'required|array|min:1|max:'.count(PermissionCatalog::LABELS),
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(array_keys(PermissionCatalog::LABELS))],
        ];
    }
}
