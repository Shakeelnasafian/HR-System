<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\CompanyRequest;
use App\Models\Organization\OrganizationUnit;

/** /organization/{kind}: an unknown kind is a 404 before the company is authorized (so before any MFA 403). */
abstract class OrganizationUnitRequest extends CompanyRequest
{
    public function authorize(): bool
    {
        abort_unless(OrganizationUnit::forKind($this->kind()) !== null, 404);

        return parent::authorize();
    }

    /** The route kind, which is also the table name and the audit action prefix. */
    public function kind(): string
    {
        return (string) $this->route('kind');
    }

    /** @return class-string<OrganizationUnit> */
    public function unitModel(): string
    {
        return OrganizationUnit::forKind($this->kind());
    }
}
