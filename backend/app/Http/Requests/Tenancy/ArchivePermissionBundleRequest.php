<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\CompanyRequest;
use App\Models\Tenancy\PermissionBundle;
use Illuminate\Support\Str;

/** A malformed, unknown or foreign bundle is a 404 before the body is validated. */
class ArchivePermissionBundleRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'access.manage';
    }

    public function authorize(): bool
    {
        abort_unless(Str::isUuid($this->bundleId()), 404);
        if (! parent::authorize()) {
            return false;
        }
        abort_unless(PermissionBundle::query()->where('company_id', $this->companyId())->whereKey($this->bundleId())->exists(), 404);

        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500', 'regex:/\S/u']];
    }

    public function bundleId(): string
    {
        return (string) $this->route('bundle');
    }
}
