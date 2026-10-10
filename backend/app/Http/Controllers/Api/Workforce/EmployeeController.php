<?php

namespace App\Http\Controllers\Api\Workforce;

use App\Actions\Workforce\CreateEmployee;
use App\Actions\Workforce\CurrentAssignments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workforce\CreateEmployeeRequest;
use App\Http\Requests\Workforce\ListEmployeesRequest;
use App\Http\Requests\Workforce\ShowEmployeeRequest;
use App\Http\Resources\Workforce\EmployeeCreatedResource;
use App\Http\Resources\Workforce\EmployeeResource;
use App\Http\Resources\Workforce\EmploymentResource;
use App\Models\Workforce\Employee;
use App\Models\Workforce\Employment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/** The company's people directory: anyone with an employment (any status) in the company. */
class EmployeeController extends Controller
{
    public function index(ListEmployeesRequest $request, string $company): AnonymousResourceCollection
    {
        $people = Employee::query()->inCompany($company)->select(Employee::DIRECTORY);
        if ($term = $request->input('q')) {
            // strpos/lower: a literal, case-insensitive substring match (no LIKE wildcards in the term).
            $people->where(fn (Builder $q) => $q->whereRaw('strpos(lower(legal_name), lower(?)) > 0', [$term])->orWhereRaw('strpos(lower(employee_number), lower(?)) > 0', [$term])
                ->orWhereRaw('strpos(lower(preferred_name), lower(?)) > 0', [$term]));
        }

        return EmployeeResource::collection($people->orderBy('legal_name')->orderBy('id')->paginate($request->perPage()));
    }

    public function store(CreateEmployeeRequest $request, CreateEmployee $create, string $company): JsonResponse
    {
        $employment = $create->handle($company, $request->validated(), $request->employmentData());

        return (new EmployeeCreatedResource($employment))->response()->setStatusCode(201);
    }

    /** The person with every employment in the company, each with its assignment current on the company's local today. */
    public function show(ShowEmployeeRequest $request, CurrentAssignments $current, string $company, string $id): array
    {
        abort_unless(Str::isUuid($id), 404);
        $person = Employee::query()->inCompany($company)->whereKey($id)->first(Employee::DIRECTORY);
        abort_unless($person, 404);
        $employments = Employment::query()->where('company_id', $company)->where('employee_id', $id)->orderByDesc('start_date')->orderBy('id')->get(Employment::FIELDS);

        return ['data' => ['employee' => new EmployeeResource($person), 'employments' => EmploymentResource::collection($current->load($employments, $company, $request->company()->today()))]];
    }
}
