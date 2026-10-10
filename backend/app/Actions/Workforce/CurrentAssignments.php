<?php

namespace App\Actions\Workforce;

use App\Models\Workforce\Employment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Read helper shared by the employee detail and the employment edit response. */
final class CurrentAssignments
{
    /**
     * Sets each employment's currentAssignment: the assignment in effect on the company's $today, clamped into the
     * employment interval so future drafts show their initial assignment and ended employments their last one.
     *
     * @param  Collection<int, Employment>  $employments
     * @return Collection<int, Employment>
     */
    public function load(Collection $employments, string $company, string $today): Collection
    {
        $employments->load(['assignments' => fn (HasMany $q) => $q->withDirectory()->where('employment_assignments.company_id', $company)]);

        return $employments->each(function (Employment $e) use ($today) {
            $asOf = max($today, $e->start_date);
            if ($e->end_date && $asOf >= $e->end_date) {
                $asOf = Carbon::parse($e->end_date)->subDay()->toDateString();
            }
            $e->setRelation('currentAssignment', $e->assignments->first(fn ($a) => $a->effective_from <= $asOf));
        });
    }
}
