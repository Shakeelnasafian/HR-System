<?php

namespace App\Http\Requests\Workforce\Concerns;

use App\Models\Workforce\Employment;
use Illuminate\Support\Str;

/**
 * Requests on /employments/{employment}: after the company is authorized, an unknown, malformed or other-company
 * employment is a 404 before any input is validated.
 */
trait ResolvesRouteEmployment
{
    private ?Employment $routeEmployment = null;

    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        $id = (string) $this->route('employment');

        return Str::isUuid($id) && ($this->routeEmployment = Employment::query()->where('company_id', $this->companyId())->whereKey($id)->first()) !== null;
    }

    /** The route employment as read during authorization (not locked). */
    public function employment(): Employment
    {
        return $this->routeEmployment;
    }
}
