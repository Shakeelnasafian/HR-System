<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\CompanyRequest;
use App\Models\Tenancy\Invitation;
use Illuminate\Support\Str;

/** Resend and cancel. A malformed, unknown or foreign invitation is a 404 before the body is validated. */
class ChangeInvitationRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'access.manage';
    }

    public function authorize(): bool
    {
        abort_unless(Str::isUuid($this->invitationId()), 404);
        if (! parent::authorize()) {
            return false;
        }
        abort_unless(Invitation::query()->where('company_id', $this->companyId())->whereKey($this->invitationId())->exists(), 404);

        return true;
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'reason' => ['required', 'string', 'max:500', 'regex:/\S/u']];
    }

    public function invitationId(): string
    {
        return (string) $this->route('invitation');
    }
}
