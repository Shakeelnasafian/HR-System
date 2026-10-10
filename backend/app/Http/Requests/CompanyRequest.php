<?php

namespace App\Http\Requests;

use App\Models\Tenancy\Company;
use App\Policies\CompanyPolicy;
use App\Services\Tenancy\CompanyAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A request against /companies/{company}/... that needs one company permission. Authorization runs before validation,
 * through CompanyPolicy, and every denial is a 404 so callers cannot tell unknown, foreign and unauthorized companies apart.
 */
abstract class CompanyRequest extends FormRequest
{
    private ?Company $resolved = null;

    /** The permission code (App\Services\Tenancy\PermissionCatalog) required in the route company. */
    abstract protected function permission(): string;

    public function authorize(): bool
    {
        return Gate::allows(CompanyPolicy::ability($this->permission()), [Company::class, $this->companyId()]);
    }

    public function rules(): array
    {
        return [];
    }

    public function companyId(): string
    {
        return (string) $this->route('company');
    }

    /**
     * The authorized route company. Mutations that depend on the company row or must serialize on it pass $lock: the row
     * is then re-read FOR UPDATE with the grant re-evaluated inside the request transaction.
     */
    public function company(bool $lock = false): Company
    {
        if ($lock) {
            return app(CompanyAccess::class)->find($this->companyId(), $this->permission(), true);
        }

        return $this->resolved ??= app(CompanyAccess::class)->find($this->companyId(), $this->permission());
    }

    protected function failedAuthorization(): never
    {
        abort(404);
    }
}
