<?php

namespace App\Http\Controllers\Api\Workforce;

use App\Actions\Workforce\TransitionEmployment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workforce\TransitionEmploymentRequest;
use App\Http\Resources\Workforce\EmploymentTransitionResource;

/** POST employments/{id}/{activate|end|cancel}. */
class EmploymentTransitionController extends Controller
{
    public function __invoke(TransitionEmploymentRequest $request, TransitionEmployment $transition, string $company, string $id, string $action): EmploymentTransitionResource
    {
        return new EmploymentTransitionResource($transition->handle($request->company(), $id, $action, $request->validated()));
    }
}
