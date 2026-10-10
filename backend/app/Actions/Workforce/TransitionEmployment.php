<?php

namespace App\Actions\Workforce;

use App\Models\Tenancy\Company;
use App\Models\Workforce\Employee;
use App\Models\Workforce\Employment;
use App\Services\Audit\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** Employment lifecycle: draft -> active (activate), active -> ended (end), draft -> cancelled (cancel). */
final class TransitionEmployment
{
    /**
     * @param  'activate'|'end'|'cancel'  $action
     * @param  array{version: int, reason: string, end_date?: string}  $data
     * @return array{id: string, status: string, version: int}
     */
    public function handle(Company $company, string $id, string $action, array $data): array
    {
        $row = Employment::query()->where('company_id', $company->id)->whereKey($id)->first();
        abort_unless($row, 404);
        // All transitions for this person serialize on the same parent, including across companies.
        Employee::query()->whereKey($row->employee_id)->lockForUpdate()->first();
        $row = Employment::query()->where('company_id', $company->id)->whereKey($id)->lockForUpdate()->first();
        abort_unless($row->version === $data['version'], 409, 'This employment changed. Reload before continuing.');
        $today = $company->today();
        $changes = [];
        if ($action === 'activate') {
            abort_unless($row->status === 'draft', 409, 'Only draft employments can be activated.');
            if ($row->start_date > $today) {
                throw ValidationException::withMessages(['start_date' => 'Activate on or after the start date.']);
            }
            $overlap = Employment::query()->where('employee_id', $row->employee_id)->where('id', '<>', $id)->whereIn('status', ['active', 'ended'])
                ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>', $row->start_date))->exists();
            abort_if($overlap, 409, 'An employment overlaps this interval. Resolve the existing relationship first.');
            $changes = ['status' => 'active'];
        } elseif ($action === 'cancel') {
            abort_unless($row->status === 'draft', 409, 'Only draft employments can be cancelled.');
            $changes = ['status' => 'cancelled'];
        } else {
            abort_unless($row->status === 'active', 409, 'Only active employments can be ended.');
            if ($data['end_date'] <= $row->start_date || $data['end_date'] > now($company->timezone)->addDay()->toDateString()) {
                throw ValidationException::withMessages(['end_date' => 'Exclusive end date must follow start and be no later than tomorrow.']);
            }
            $changes = ['status' => 'ended', 'end_date' => $data['end_date']];
        }
        $from = $row->status;
        $row->forceFill($changes + ['version' => $row->version + 1])->save();
        Audit::record($company->id, 'employment.'.$action, $id, ['from' => $from, 'to' => $changes['status'], 'fields' => array_keys($changes)], $data['reason']);

        return ['id' => $id, 'status' => $changes['status'], 'version' => $row->version];
    }
}
