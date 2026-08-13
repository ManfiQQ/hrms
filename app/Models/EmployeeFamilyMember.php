<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A DESCRIPTIVE child of the employee — `company_id` is a tenant marker denormalized from the
 * parent, and it CASCADES on a company transfer (adr/0003 decision 7).
 *
 * ⚠ Read access is narrower than the employee record itself. Supervisors, Managers and HODs
 * read Employment and Personal only; Family is HR / Assistant Director / Account / Master
 * Admin, plus the employee's own (employee-master.spec.md §6.2). The one exception is the
 * EMERGENCY CONTACT — name and number, surfaced on the Employment tab — because a supervisor
 * is likely the first person present at an accident and should not need the whole family
 * record to make one call. That exception is a read-side concern; this model holds the data.
 */
class EmployeeFamilyMember extends Model
{
    use HasFactory, SoftDeletes;

    /** Narrows every query to the account's read scope (adr/0005 decision 2). */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'company_id',
        'employee_id',
        'relationship',
        'name',
        'contact_no',
        'is_emergency_contact',
    ];

    protected function casts(): array
    {
        return [
            'is_emergency_contact' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
