<?php

namespace App\Actions\Organization;

use App\Models\Organization\WorkingCalendar;
use App\Models\Tenancy\Company;
use App\Services\Audit\Audit;

/** Renames or archives/restores a calendar. A request that changes nothing neither bumps the version nor audits. */
class UpdateCalendar
{
    public function __construct(private readonly LockCalendar $lock) {}

    public function handle(Company $company, string $calendar, array $data): WorkingCalendar
    {
        $row = $this->lock->handle($company, $calendar, $data['version'], false);
        if (array_key_exists('archived', $data)) {
            $data['archived'] = (bool) $data['archived'];
        }
        $changes = array_filter(array_intersect_key($data, ['name' => 1, 'archived' => 1]), fn ($value, $key) => $value !== $row->$key, ARRAY_FILTER_USE_BOTH);
        if ($changes) {
            $row->bumpVersion($changes);
            Audit::record($company->id, 'calendar.updated', $row->id, ['code' => $row->code, 'fields' => array_keys($changes)], $data['reason']);
        }

        return $row;
    }
}
