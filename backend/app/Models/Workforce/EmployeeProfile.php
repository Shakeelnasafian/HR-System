<?php

namespace App\Models\Workforce;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Private profile data (profile.read / profile.write, both privileged). Values never enter audit records, directory or
 * assignment payloads. One row per employee, created on first write; version 0 means no row yet.
 */
class EmployeeProfile extends Model
{
    use BelongsToTenant;

    /** Collectable fields, in display order; each company enables a subset. */
    public const FIELDS = ['birth_date', 'nationality', 'personal_email', 'personal_phone', 'address', 'emergency_contacts'];

    protected $primaryKey = 'employee_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['employee_id', ...self::FIELDS];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
