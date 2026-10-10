<?php

namespace App\Workforce;

use App\Http\Resources\ProjectedRow;
use App\Services\Audit\Audit;
use App\Services\Tenancy\ScopesCompany;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WorkforceController
{
    use ScopesCompany;

    private const KINDS = ['departments', 'locations', 'positions', 'employment_types'];

    private function page(Request $r, Builder $q): JsonResource
    {
        $r->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return ProjectedRow::collection($q->paginate((int) $r->input('per_page', 25)));
    }

    public function capabilities(string $company): array
    {
        $this->company($company, 'company.read');
        $context = app(TenantContext::class);

        return ['data' => DB::table('company_grants as g')->join('tenant_memberships as m', 'm.id', '=', 'g.membership_id')
            ->where('g.tenant_id', $this->tenant())->where('g.company_id', $company)->where('m.user_id', $context->userId())
            ->where('m.status', 'active')->orderBy('g.permission')->pluck('g.permission')];
    }

    public function organization(Request $r, string $company, string $kind): JsonResource
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $this->company($company, 'organization.read');

        return $this->page($r, $this->rows($kind, $company)->orderBy('name')->orderBy('id')->select(['id', 'code', 'name', 'archived', 'version']));
    }

    public function createOrganization(Request $r, string $company, string $kind)
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $this->company($company, 'organization.write');
        $data = $r->validate(['code' => 'required|string|max:40|regex:/^[A-Za-z0-9_-]+$/', 'name' => 'required|string|max:160']);
        $data['code'] = strtoupper($data['code']);
        if ($this->rows($kind, $company)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'This code is already in use.']);
        }
        $id = (string) Str::uuid();
        $this->rows($kind, $company)->insert($data + ['id' => $id, 'tenant_id' => $this->tenant(), 'company_id' => $company, 'created_at' => now(), 'updated_at' => now()]);
        Audit::record($company, $kind.'.created', $id, ['fields' => array_keys($data)]);

        return response()->json(['data' => $this->rows($kind, $company)->where('id', $id)->first(['id', 'code', 'name', 'archived', 'version'])], 201);
    }

    public function updateOrganization(Request $r, string $company, string $kind, string $id): array
    {
        abort_unless(in_array($kind, self::KINDS, true) && Str::isUuid($id), 404);
        $this->company($company, 'organization.write');
        $data = $r->validate(['version' => 'required|integer|min:1', 'name' => 'sometimes|required|string|max:160', 'archived' => 'sometimes|required|boolean']);
        $row = $this->rows($kind, $company)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404);
        abort_unless($row->version === $data['version'], 409, 'This record changed. Reload before saving.');
        unset($data['version']);
        $this->rows($kind, $company)->where('id', $id)->update($data + ['version' => $row->version + 1, 'updated_at' => now()]);
        Audit::record($company, $kind.'.updated', $id, ['fields' => array_keys($data)]);

        return ['data' => $this->rows($kind, $company)->where('id', $id)->first(['id', 'code', 'name', 'archived', 'version'])];
    }

    private function people(string $company): Builder
    {
        return DB::table('employees as e')->where('e.tenant_id', $this->tenant())->whereExists(function (Builder $q) use ($company) {
            $q->selectRaw('1')->from('employments as j')->whereColumn('j.employee_id', 'e.id')->whereColumn('j.tenant_id', 'e.tenant_id')->where('j.company_id', $company);
        });
    }

    public function employees(Request $r, string $company): JsonResource
    {
        $this->company($company, 'workforce.read');
        $r->validate(['q' => 'sometimes|nullable|string|max:100']);
        $q = $this->people($company)->select(['e.id', 'e.employee_number', 'e.legal_name', 'e.preferred_name']);
        if ($term = $r->input('q')) {
            $q->where(fn (Builder $q) => $q->whereRaw('strpos(lower(legal_name), lower(?)) > 0', [$term])->orWhereRaw('strpos(lower(employee_number), lower(?)) > 0', [$term])->orWhereRaw('strpos(lower(preferred_name), lower(?)) > 0', [$term]));
        }

        return $this->page($r, $q->orderBy('e.legal_name')->orderBy('e.id'));
    }

    public function employee(string $company, string $id): array
    {
        $row = $this->company($company, 'workforce.read');
        abort_unless(Str::isUuid($id), 404);
        $person = $this->people($company)->where('e.id', $id)->first(['e.id', 'e.employee_number', 'e.legal_name', 'e.preferred_name']);
        abort_unless($person, 404);
        $employments = $this->rows('employments', $company)->where('employee_id', $id)->orderByDesc('start_date')->orderBy('id')->get(Assignments::EMPLOYMENT);

        return ['data' => ['employee' => $person, 'employments' => app(Assignments::class)->withCurrent($company, $employments, now($row->timezone)->toDateString())]];
    }

    private function employmentData(Request $r, string $company): array
    {
        $data = $r->validate(['employment_number' => 'required|string|max:40|regex:/^[A-Za-z0-9_-]+$/', 'start_date' => 'required|date_format:Y-m-d',
            'probation_end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date'] + Assignments::rules('sometimes'));
        $data['employment_number'] = strtoupper($data['employment_number']);
        if ($this->rows('employments', $company)->where('employment_number', $data['employment_number'])->exists()) {
            throw ValidationException::withMessages(['employment_number' => 'This employment number is already in use.']);
        }

        return $data;
    }

    /** The employment and its initial assignment at start_date are created together. Rehires with a manager also need rehire()'s cycle check. */
    private function insertEmployment(array $data, string $company, string $employee): string
    {
        $assignments = app(Assignments::class);
        $refs = array_intersect_key($data, array_flip(Assignments::KEYS));
        $assignments->check($company, $refs, $data['start_date'], $employee);
        $id = (string) Str::uuid();
        DB::table('employments')->insert(array_diff_key($data, $refs) + ['id' => $id, 'tenant_id' => $this->tenant(), 'company_id' => $company, 'employee_id' => $employee, 'created_at' => now(), 'updated_at' => now()]);
        $assignment = $assignments->insert($company, $id, $data['start_date'], $refs, null);
        Audit::record($company, 'employment.created', $id, ['employee_id' => $employee, 'status' => 'draft', 'assignment_id' => $assignment, 'fields' => array_keys(array_filter($data, fn ($v) => $v !== null))]);

        return $id;
    }

    public function createEmployee(Request $r, string $company)
    {
        $this->company($company, 'workforce.write');
        $person = $r->validate(['employee_number' => 'required|string|max:40|regex:/^[A-Za-z0-9_-]+$/', 'legal_name' => 'required|string|max:160', 'preferred_name' => 'nullable|string|max:160']);
        $person['employee_number'] = strtoupper($person['employee_number']);
        $data = $this->employmentData($r, $company);
        if (DB::table('employees')->where('tenant_id', $this->tenant())->where('employee_number', $person['employee_number'])->exists()) {
            throw ValidationException::withMessages(['employee_number' => 'This employee number is unavailable.']);
        }
        $id = (string) Str::uuid();
        DB::table('employees')->insert($person + ['id' => $id, 'tenant_id' => $this->tenant(), 'created_at' => now(), 'updated_at' => now()]);
        Audit::record($company, 'employee.created', $id, ['fields' => array_keys($person)]);
        $employment = $this->insertEmployment($data, $company, $id);

        return response()->json(['data' => ['id' => $id, 'employment_id' => $employment]], 201);
    }

    public function rehire(Request $r, string $company, string $id)
    {
        $this->company($company, 'workforce.write');
        abort_unless(Str::isUuid($id), 404);
        $assignments = app(Assignments::class);
        $reporting = ! empty($r->input('manager_employment_id'));
        if ($reporting) {
            $assignments->lockReportingLines($company);
        } // before the person lock, as for assignment changes
        abort_unless($this->people($company)->where('e.id', $id)->lockForUpdate()->first(), 404);
        $data = $this->employmentData($r, $company);
        $employment = $this->insertEmployment($data, $company, $id);
        // The person may already manage someone through another employment, so a manager here can close a cycle between people.
        if ($reporting) {
            $assignments->assertAcyclic($company, $employment, $data['start_date'], null);
        }

        return response()->json(['data' => ['id' => $employment]], 201);
    }

    public function transition(Request $r, string $company, string $id, string $action): array
    {
        abort_unless(Str::isUuid($id) && in_array($action, ['activate', 'end', 'cancel'], true), 404);
        $companyRow = $this->company($company, 'workforce.write');
        $data = $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|max:500', 'end_date' => ($action === 'end' ? 'required' : 'prohibited').'|date_format:Y-m-d']);
        $row = $this->rows('employments', $company)->where('id', $id)->first();
        abort_unless($row, 404);
        // All transitions for this person serialize on the same parent, including across companies.
        DB::table('employees')->where('tenant_id', $this->tenant())->where('id', $row->employee_id)->lockForUpdate()->first();
        $row = $this->rows('employments', $company)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row->version === $data['version'], 409, 'This employment changed. Reload before continuing.');
        $today = now($companyRow->timezone)->toDateString();
        $changes = [];
        if ($action === 'activate') {
            abort_unless($row->status === 'draft', 409, 'Only draft employments can be activated.');
            if ($row->start_date > $today) {
                throw ValidationException::withMessages(['start_date' => 'Activate on or after the start date.']);
            }
            $overlap = DB::table('employments')->where('tenant_id', $this->tenant())->where('employee_id', $row->employee_id)->where('id', '<>', $id)->whereIn('status', ['active', 'ended'])
                ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>', $row->start_date))->exists();
            abort_if($overlap, 409, 'An employment overlaps this interval. Resolve the existing relationship first.');
            $changes = ['status' => 'active'];
        } elseif ($action === 'cancel') {
            abort_unless($row->status === 'draft', 409, 'Only draft employments can be cancelled.');
            $changes = ['status' => 'cancelled'];
        } else {
            abort_unless($row->status === 'active', 409, 'Only active employments can be ended.');
            if ($data['end_date'] <= $row->start_date || $data['end_date'] > now($companyRow->timezone)->addDay()->toDateString()) {
                throw ValidationException::withMessages(['end_date' => 'Exclusive end date must follow start and be no later than tomorrow.']);
            }
            $changes = ['status' => 'ended', 'end_date' => $data['end_date']];
        }
        $this->rows('employments', $company)->where('id', $id)->update($changes + ['version' => $row->version + 1, 'updated_at' => now()]);
        Audit::record($company, 'employment.'.$action, $id, ['from' => $row->status, 'to' => $changes['status'], 'fields' => array_keys($changes)], $data['reason']);

        return ['data' => ['id' => $id, 'status' => $changes['status'], 'version' => $row->version + 1]];
    }

    public function audit(Request $r, string $company): JsonResource
    {
        $this->company($company, 'audit.read');

        return $this->page($r, $this->rows('audit_events', $company)->orderByDesc('seq')->select(['id', 'actor_id', 'action', 'resource_id', 'correlation_id', 'changes', 'reason', 'occurred_at']));
    }
}
