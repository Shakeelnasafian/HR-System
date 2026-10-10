<?php

namespace App\Http\Requests\Workforce;

use App\Http\Requests\CompanyRequest;
use App\Http\Requests\Workforce\Concerns\ResolvesRouteEmployment;

class UpdateEmploymentRequest extends CompanyRequest
{
    use ResolvesRouteEmployment;

    protected function permission(): string
    {
        return 'workforce.write';
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'reason' => 'required|string|max:500', 'probation_end_date' => 'present|nullable|date_format:Y-m-d'];
    }
}
