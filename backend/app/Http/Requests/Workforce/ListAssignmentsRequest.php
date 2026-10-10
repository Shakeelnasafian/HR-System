<?php

namespace App\Http\Requests\Workforce;

use App\Http\Requests\CompanyRequest;
use App\Http\Requests\Workforce\Concerns\ResolvesRouteEmployment;

class ListAssignmentsRequest extends CompanyRequest
{
    use ResolvesRouteEmployment;

    protected function permission(): string
    {
        return 'workforce.read';
    }
}
