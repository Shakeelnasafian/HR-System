<?php

namespace App\Http\Controllers\Api\Workforce;

use App\Actions\Workforce\AddAssignment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workforce\ListAssignmentsRequest;
use App\Http\Requests\Workforce\StoreAssignmentRequest;
use App\Http\Resources\Workforce\AddedAssignmentResource;
use App\Http\Resources\Workforce\AssignmentResource;
use App\Models\Workforce\EmploymentAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Effective-dated assignment history of an employment (newest first) and appending to it. */
class AssignmentController extends Controller
{
    public function index(ListAssignmentsRequest $request, string $company, string $employment): AnonymousResourceCollection
    {
        return AssignmentResource::collection(EmploymentAssignment::query()->withDirectory()->where('employment_assignments.company_id', $company)
            ->where('employment_assignments.employment_id', $request->employment()->id)->get());
    }

    public function store(StoreAssignmentRequest $request, AddAssignment $add, string $company, string $employment): JsonResponse
    {
        return (new AddedAssignmentResource($add->handle($company, $request->employment()->id, $request->validated())))->response()->setStatusCode(201);
    }
}
