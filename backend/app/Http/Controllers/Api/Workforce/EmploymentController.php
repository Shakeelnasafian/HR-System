<?php

namespace App\Http\Controllers\Api\Workforce;

use App\Actions\Workforce\RehireEmployee;
use App\Actions\Workforce\UpdateEmployment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workforce\RehireEmployeeRequest;
use App\Http\Requests\Workforce\UpdateEmploymentRequest;
use App\Http\Resources\Workforce\EmploymentCreatedResource;
use App\Http\Resources\Workforce\EmploymentResource;
use Illuminate\Http\JsonResponse;

/** Adding an employment to an existing person (rehire) and employment-level edits (probation). */
class EmploymentController extends Controller
{
    public function store(RehireEmployeeRequest $request, RehireEmployee $rehire, string $company, string $id): JsonResponse
    {
        return (new EmploymentCreatedResource($rehire->handle($company, $id, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(UpdateEmploymentRequest $request, UpdateEmployment $update, string $company, string $employment): EmploymentResource
    {
        return new EmploymentResource($update->handle($request->company(), $request->employment()->id, $request->validated()));
    }
}
