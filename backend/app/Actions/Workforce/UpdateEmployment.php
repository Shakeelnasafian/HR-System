<?php

namespace App\Actions\Workforce;

use App\Models\Tenancy\Company;
use App\Models\Workforce\Employment;
use App\Services\Audit\Audit;
use Illuminate\Validation\ValidationException;

/** Employment-level edits (the probation end date); placement changes are assignments. */
final class UpdateEmployment
{
    public function __construct(private readonly CurrentAssignments $currentAssignments) {}

    /** @param  array{version: int, reason: string, probation_end_date: ?string}  $data */
    public function handle(Company $company, string $id, array $data): Employment
    {
        $row = Employment::query()->where('company_id', $company->id)->whereKey($id)->lockForUpdate()->first();
        abort_unless($row, 404);
        abort_unless($row->version === $data['version'], 409, 'This employment changed. Reload before continuing.');
        abort_if($row->status === 'cancelled', 409, 'Cancelled employments cannot be changed.');
        if ($data['probation_end_date'] !== null && $data['probation_end_date'] < $row->start_date) {
            throw ValidationException::withMessages(['probation_end_date' => 'The probation end date cannot be before the start date.']);
        }
        if ($row->probation_end_date !== $data['probation_end_date']) {
            $row->forceFill(['probation_end_date' => $data['probation_end_date'], 'version' => $row->version + 1])->save();
            Audit::record($company->id, 'employment.updated', $row->id, ['fields' => ['probation_end_date']], $data['reason']);
        }
        $fresh = Employment::query()->where('company_id', $company->id)->whereKey($row->id)->get(Employment::FIELDS);

        return $this->currentAssignments->load($fresh, $company->id, $company->today())->first();
    }
}
