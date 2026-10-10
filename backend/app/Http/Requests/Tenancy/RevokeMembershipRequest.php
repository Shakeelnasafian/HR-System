<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\CompanyRequest;
use App\Models\Tenancy\TenantMembership;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;

/** Self-removal (403) and unknown, revoked or out-of-company members (404) are refused before the body is validated. */
class RevokeMembershipRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'access.manage';
    }

    public function authorize(): bool
    {
        abort_unless(Str::isUuid($this->membershipId()), 404);
        if (! parent::authorize()) {
            return false;
        }
        $actor = TenantMembership::query()->active()->where('user_id', app(TenantContext::class)->userId())->firstOr(fn () => abort(404, 'Not found.'));
        abort_if($actor->id === $this->membershipId(), 403, 'You cannot remove yourself from the organization.');
        abort_unless(TenantMembership::query()->withAccessTo($this->companyId())->whereKey($this->membershipId())->where('status', '!=', 'revoked')->exists(), 404);

        return true;
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'reason' => ['required', 'string', 'max:500', 'regex:/\S/u']];
    }

    public function membershipId(): string
    {
        return (string) $this->route('membership');
    }
}
