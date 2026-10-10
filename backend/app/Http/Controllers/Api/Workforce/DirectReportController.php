<?php

namespace App\Http\Controllers\Api\Workforce;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workforce\ListDirectReportsRequest;
use App\Http\Resources\Workforce\DirectReportResource;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** Direct reports whose assignment in effect on the company's today names this employment and whose employment covers today. */
class DirectReportController extends Controller
{
    public function index(ListDirectReportsRequest $request, TenantContext $context, string $company, string $employment): AnonymousResourceCollection
    {
        $today = $request->company()->today();
        // Query builder: DISTINCT ON picks each employment's latest assignment by today in one pass.
        $current = DB::table('employment_assignments')->where('tenant_id', $context->id())->where('company_id', $company)->selectRaw('DISTINCT ON (employment_id) employment_id, manager_employment_id')
            ->where('effective_from', '<=', $today)->orderBy('employment_id')->orderByDesc('effective_from');
        $reports = DB::query()->fromSub($current, 'cur')
            ->join('employments as j', fn ($j) => $j->on('j.id', '=', 'cur.employment_id')->where('j.tenant_id', $context->id())->where('j.company_id', $company))
            ->join('employees as e', fn ($j) => $j->on('e.id', '=', 'j.employee_id')->on('e.tenant_id', '=', 'j.tenant_id'))
            ->where('cur.manager_employment_id', $request->employment()->id)->where('j.status', '<>', 'cancelled')->where('j.start_date', '<=', $today)
            ->where(fn ($q) => $q->whereNull('j.end_date')->orWhere('j.end_date', '>', $today))
            ->orderBy('e.legal_name')->orderBy('j.id')
            ->select(['j.id as employment_id', 'j.employment_number', 'j.status', 'e.id as employee_id', 'e.employee_number', 'e.legal_name', 'e.preferred_name']);

        return DirectReportResource::collection($reports->paginate($request->perPage()));
    }
}
