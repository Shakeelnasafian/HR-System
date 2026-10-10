<?php

namespace App\Http\Requests\Workforce;

/** Only the envelope is validated here; field values are validated against the company's enabled set inside the action. */
class UpdateEmployeeProfileRequest extends EmployeeProfileRequest
{
    protected function permission(): string
    {
        return 'profile.write';
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:0', 'reason' => 'required|string|max:500', 'fields' => 'required|array|min:1'];
    }
}
