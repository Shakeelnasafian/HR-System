<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\CompanyRequest;
use App\Http\Requests\Concerns\Paginates;

class ListInvitationsRequest extends CompanyRequest
{
    use Paginates;

    protected function permission(): string
    {
        return 'access.manage';
    }

    public function rules(): array
    {
        return ['status' => 'sometimes|in:pending,all'] + $this->paginationRules();
    }

    /** Only pending, unexpired invitations unless status=all. */
    public function onlyPending(): bool
    {
        return $this->input('status', 'pending') === 'pending';
    }
}
