<?php

namespace App\Http\Requests\Workforce;

use App\Http\Requests\CompanyRequest;
use App\Http\Requests\Concerns\Paginates;
use Illuminate\Support\Facades\Validator;

class ListEmployeesRequest extends CompanyRequest
{
    use Paginates;

    protected function permission(): string
    {
        return 'workforce.read';
    }

    public function rules(): array
    {
        return ['q' => 'sometimes|nullable|string|max:100'];
    }

    /** Pagination is validated once the search term passes, as a second stage (errors are reported per stage). */
    protected function passedValidation(): void
    {
        Validator::make($this->all(), $this->paginationRules())->validate();
    }
}
