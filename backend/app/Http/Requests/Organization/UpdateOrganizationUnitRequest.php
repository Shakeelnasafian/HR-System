<?php

namespace App\Http\Requests\Organization;

use Illuminate\Support\Str;

/** The unit row itself is resolved (and locked) after validation, so an unknown well-formed id with invalid input is a 422. */
class UpdateOrganizationUnitRequest extends OrganizationUnitRequest
{
    /** A malformed id is a 404 like an unknown kind, before the company is authorized. */
    public function authorize(): bool
    {
        abort_unless(Str::isUuid($this->unitId()), 404);

        return parent::authorize();
    }

    protected function permission(): string
    {
        return 'organization.write';
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'name' => 'sometimes|required|string|max:160', 'archived' => 'sometimes|required|boolean'];
    }

    public function unitId(): string
    {
        return (string) $this->route('id');
    }
}
