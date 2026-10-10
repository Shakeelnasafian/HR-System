<?php

namespace App\Http\Requests\Audit;

use App\Http\Requests\CompanyRequest;
use App\Http\Requests\Concerns\Paginates;

class ListAuditEventsRequest extends CompanyRequest
{
    use Paginates;

    protected function permission(): string
    {
        return 'audit.read';
    }

    public function rules(): array
    {
        return $this->paginationRules();
    }
}
