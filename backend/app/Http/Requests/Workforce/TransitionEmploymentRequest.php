<?php

namespace App\Http\Requests\Workforce;

use App\Http\Requests\CompanyRequest;
use Illuminate\Support\Str;

/** POST employments/{id}/{action}; the employment itself is resolved after validation (validation errors come first). */
class TransitionEmploymentRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'workforce.write';
    }

    public function authorize(): bool
    {
        // A malformed id is a 404 before the company is resolved.
        abort_unless(Str::isUuid((string) $this->route('id')), 404);

        return parent::authorize();
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'reason' => 'required|string|max:500', 'end_date' => ($this->route('action') === 'end' ? 'required' : 'prohibited').'|date_format:Y-m-d'];
    }
}
