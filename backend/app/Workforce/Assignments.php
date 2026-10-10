<?php

namespace App\Workforce;

use App\Services\Tenancy\ScopesCompany;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Effective-dated employment assignments (append-only). Shared by employment creation and assignment changes. */
final class Assignments
{
    use ScopesCompany;

    public const REFS = ['department_id' => 'departments', 'location_id' => 'locations', 'position_id' => 'positions', 'employment_type_id' => 'employment_types', 'calendar_id' => 'working_calendars'];

    public const KEYS = ['department_id', 'location_id', 'position_id', 'employment_type_id', 'calendar_id', 'manager_employment_id'];

    public const EMPLOYMENT = ['id', 'employment_number', 'start_date', 'end_date', 'status', 'version', 'probation_end_date'];

    public static function rules(string $presence): array
    {
        return array_fill_keys(self::KEYS, $presence.'|nullable|uuid');
    }

    /**
     * References must be active same-company records; a manager must be another person's draft/active employment started by $date.
     * Keys in $inherited were copied forward rather than supplied: they are re-validated (never silently cleared) with a distinct message.
     */
    public function check(string $company, array $refs, string $date, string $employee, array $inherited = []): void
    {
        $errors = [];
        foreach (self::REFS as $key => $table) {
            if (! empty($refs[$key]) && ! $this->rows($table, $company)->where('id', $refs[$key])->where('archived', false)->sharedLock()->first()) {
                $errors[$key] = 'Select an active record in this company.';
            }
        }
        if (! empty($refs['manager_employment_id'])) {
            $q = $this->rows('employments', $company)->where('id', $refs['manager_employment_id']);
            // An inherited manager is only re-read: locking it outside the reporting-line lock could deadlock with the manager's own edit.
            $manager = (in_array('manager_employment_id', $inherited, true) ? $q : $q->sharedLock())->first();
            if (! $manager || ! in_array($manager->status, ['draft', 'active'], true) || $manager->start_date > $date) {
                $errors['manager_employment_id'] = 'Select a draft or active employment in this company that starts by the effective date.';
            } elseif ($manager->employee_id === $employee) {
                $errors['manager_employment_id'] = 'An employee cannot be their own manager.';
            }
        }
        foreach (array_intersect_key($errors, array_flip($inherited)) as $key => $message) {
            $errors[$key] = 'The current value is no longer active. Choose another or clear it explicitly.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function insert(string $company, string $employment, string $date, array $values, ?string $reason): string
    {
        $id = (string) Str::uuid();
        DB::table('employment_assignments')->insert(['id' => $id, 'tenant_id' => $this->tenant(), 'company_id' => $company, 'employment_id' => $employment, 'effective_from' => $date,
            'created_by' => app(TenantContext::class)->userId(), 'reason' => $reason, 'created_at' => now()] + array_intersect_key($values, array_flip(self::KEYS)));

        return $id;
    }

    /** Serializes reporting-line changes per company so two concurrent changes cannot each pass the cycle check. */
    public function lockReportingLines(string $company): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr.reporting_line:'.$this->tenant().':'.$company]);
    }

    /**
     * After inserting $employment's assignment at $from, walks reporting lines between people (not employments: one person may hold
     * several) as of $from and every later assignment date in the company before $until, the next assignment of $employment; outside
     * that window its link is unchanged. Only employments covering the date, on both ends of a link, contribute, so ended history does not block rehires.
     */
    public function assertAcyclic(string $company, string $employment, string $from, ?string $until): void
    {
        $t = $this->tenant();
        $person = $this->rows('employments', $company)->where('id', $employment)->value('employee_id');
        // One step: from person r.emp on date r.d to the people managing any of their employments in effect on r.d.
        $step = fn (string $source) => "FROM $source JOIN employments j ON j.tenant_id = :t AND j.company_id = :c AND j.employee_id = r.emp AND j.status <> 'cancelled'
                AND j.start_date <= r.d AND (j.end_date IS NULL OR j.end_date > r.d)
            CROSS JOIN LATERAL (SELECT a.manager_employment_id AS manager FROM employment_assignments a
                WHERE a.tenant_id = :t AND a.company_id = :c AND a.employment_id = j.id AND a.effective_from <= r.d ORDER BY a.effective_from DESC LIMIT 1) l
            JOIN employments m ON m.tenant_id = :t AND m.company_id = :c AND m.id = l.manager AND m.status <> 'cancelled'
                AND m.start_date <= r.d AND (m.end_date IS NULL OR m.end_date > r.d)";
        $sql = 'WITH RECURSIVE dates(d) AS (
                SELECT CAST(:from AS date) UNION SELECT effective_from FROM employment_assignments WHERE tenant_id = :t AND company_id = :c AND effective_from > CAST(:from AS date)
                    AND (CAST(:until AS date) IS NULL OR effective_from < CAST(:until AS date))
            ), reach(d, emp) AS (
                SELECT r.d, m.employee_id '.$step('(SELECT d, CAST(:person AS uuid) AS emp FROM dates) r').'
                UNION
                SELECT r.d, m.employee_id '.$step('reach r').' WHERE r.emp <> CAST(:person AS uuid)
            ) SELECT min(d) AS d FROM reach WHERE emp = CAST(:person AS uuid)';
        $named = ['from' => $from, 'until' => $until, 't' => $t, 'c' => $company, 'person' => $person];
        $bindings = [];
        // Named markers repeat, so expand them to positional bindings explicitly.
        $sql = preg_replace_callback('/:(from|until|t|c|person)\b/', function ($m) use (&$bindings, $named) {
            $bindings[] = $named[$m[1]];

            return '?';
        }, $sql);
        $cycle = DB::selectOne($sql, $bindings);
        if ($cycle->d) {
            throw ValidationException::withMessages(['manager_employment_id' => "This reporting line would create a cycle on {$cycle->d}."]);
        }
    }

    /** Assignment rows with referenced codes/names and the manager's safe directory fields. */
    public function query(string $company): Builder
    {
        $q = DB::table('employment_assignments as a')->where('a.tenant_id', $this->tenant())->where('a.company_id', $company)
            ->select(['a.id', 'a.employment_id', 'a.effective_from', 'a.reason', 'a.created_at', ...array_map(fn ($k) => "a.$k", self::KEYS)]);
        foreach (self::REFS as $key => $table) {
            $alias = 'r_'.$key;
            $q->leftJoin("$table as $alias", fn ($j) => $j->on("$alias.tenant_id", '=', 'a.tenant_id')->on("$alias.company_id", '=', 'a.company_id')->on("$alias.id", '=', "a.$key"))
                ->addSelect(["$alias.code as {$key}_code", "$alias.name as {$key}_name"]);
        }

        return $q->leftJoin('employments as mgr', fn ($j) => $j->on('mgr.tenant_id', '=', 'a.tenant_id')->on('mgr.company_id', '=', 'a.company_id')->on('mgr.id', '=', 'a.manager_employment_id'))
            ->leftJoin('employees as mgr_person', fn ($j) => $j->on('mgr_person.tenant_id', '=', 'mgr.tenant_id')->on('mgr_person.id', '=', 'mgr.employee_id'))
            ->addSelect(['mgr.employment_number as manager_employment_number', 'mgr.employee_id as manager_employee_id', 'mgr_person.employee_number as manager_employee_number',
                'mgr_person.legal_name as manager_legal_name', 'mgr_person.preferred_name as manager_preferred_name'])
            ->orderByDesc('a.effective_from')->orderBy('a.id');
    }

    public static function present(object $a): array
    {
        $out = ['id' => $a->id, 'employment_id' => $a->employment_id, 'effective_from' => $a->effective_from];
        foreach (self::REFS as $key => $table) {
            $out[substr($key, 0, -3)] = $a->$key ? ['id' => $a->$key, 'code' => $a->{$key.'_code'}, 'name' => $a->{$key.'_name'}] : null;
        }
        $out['manager'] = $a->manager_employment_id ? ['employment_id' => $a->manager_employment_id, 'employment_number' => $a->manager_employment_number, 'employee_id' => $a->manager_employee_id,
            'employee_number' => $a->manager_employee_number, 'legal_name' => $a->manager_legal_name, 'preferred_name' => $a->manager_preferred_name] : null;

        return $out + ['reason' => $a->reason, 'created_at' => $a->created_at];
    }

    /** The assignment in effect on $date, as raw ids (for copy-forward), or null before the first assignment. */
    public function inEffect(string $company, string $employment, string $date): ?object
    {
        return $this->rows('employment_assignments', $company)->where('employment_id', $employment)->where('effective_from', '<=', $date)->orderByDesc('effective_from')->first();
    }

    /**
     * Adds current_assignment to employment rows: the assignment in effect on the company's today, clamped into the
     * employment interval so future drafts show their initial assignment and ended employments their last one.
     */
    public function withCurrent(string $company, Collection $employments, string $today): Collection
    {
        $all = $this->query($company)->whereIn('a.employment_id', $employments->pluck('id'))->get()->groupBy('employment_id');

        return $employments->map(function ($e) use ($all, $today) {
            $asOf = max($today, $e->start_date);
            if ($e->end_date && $asOf >= $e->end_date) {
                $asOf = Carbon::parse($e->end_date)->subDay()->toDateString();
            }
            $current = ($all[$e->id] ?? collect())->first(fn ($a) => $a->effective_from <= $asOf);

            return (array) $e + ['current_assignment' => $current ? self::present($current) : null];
        });
    }
}
