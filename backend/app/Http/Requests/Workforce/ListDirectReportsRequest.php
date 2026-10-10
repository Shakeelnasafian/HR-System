<?php

namespace App\Http\Requests\Workforce;

use App\Http\Requests\CompanyRequest;
use App\Http\Requests\Concerns\Paginates;
use App\Http\Requests\Workforce\Concerns\ResolvesRouteEmployment;

class ListDirectReportsRequest extends CompanyRequest
{
    use Paginates, ResolvesRouteEmployment;

    protected function permission(): string
    {
        return 'workforce.read';
    }

    public function rules(): array
    {
        return $this->paginationRules();
    }
}
