<?php

namespace App\Http\Requests\Workforce;

class ShowEmployeeProfileRequest extends EmployeeProfileRequest
{
    protected function permission(): string
    {
        return 'profile.read';
    }
}
