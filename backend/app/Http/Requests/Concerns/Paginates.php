<?php

namespace App\Http\Requests\Concerns;

/** page/per_page query parameters for length-aware paginated listings (25 per page by default, at most 100). */
trait Paginates
{
    /** @return array<string, string> */
    protected function paginationRules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 25);
    }
}
