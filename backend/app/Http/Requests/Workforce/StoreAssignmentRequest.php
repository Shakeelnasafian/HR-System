<?php

namespace App\Http\Requests\Workforce;

use App\Http\Requests\CompanyRequest;
use App\Http\Requests\Workforce\Concerns\EmploymentRules;
use App\Http\Requests\Workforce\Concerns\ResolvesRouteEmployment;

class StoreAssignmentRequest extends CompanyRequest
{
    use EmploymentRules, ResolvesRouteEmployment;

    protected function permission(): string
    {
        return 'workforce.write';
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'reason' => 'required|string|max:500', 'effective_from' => 'required|date_format:Y-m-d'] + $this->assignmentRules();
    }
}
