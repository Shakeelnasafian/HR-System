<?php

namespace App\Actions\Workforce;

use App\Models\Workforce\Employment;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Reporting-line integrity: the per-company advisory lock and the person-level cycle check. */
final class ReportingLine
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Serializes reporting-line changes per company so two concurrent changes cannot each pass the cycle check. Taken
     * before the employee and employment row locks.
     */
    public function lock(string $company): void
    {
        // Advisory lock: no row represents "the company's reporting lines".
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr.reporting_line:'.$this->context->id().':'.$company]);
    }

    /**
     * After inserting $employment's assignment at $from, walks reporting lines between people (not employments: one person may hold
     * several) as of $from and every later assignment date in the company before $until, the next assignment of $employment; outside
     * that window its link is unchanged. Only employments covering the date, on both ends of a link, contribute, so ended history does not block rehires.
     */
    public function assertAcyclic(string $company, string $employment, string $from, ?string $until): void
    {
        $t = $this->context->id();
        $person = Employment::query()->where('company_id', $company)->whereKey($employment)->value('employee_id');
        // Recursive CTE over dated links; kept as SQL. One step: from person r.emp on date r.d to the people managing any of their employments in effect on r.d.
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
}
