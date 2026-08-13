<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The job-function vocabulary — a reference table, not an enum (adr/0003 decision 2).
 *
 * ⚠ NO TENANT SCOPE, AND THE TABLE HAS NO `company_id`. The vocabulary is GROUP-WIDE and
 * Master Admin owns it; HR only assigns from it. A per-company list would be six lists, and
 * six lists is how one thing acquires three spellings (CLAUDE.md §5). The scope guard test
 * skips this model by there being no column to scope, the same footing as `Sequence` — not by
 * an opt-out.
 *
 * ⚠ DEACTIVATION IS THE SOFT DELETE. There is no `is_active` column and none may be added:
 * two columns for one state is the pattern rejected by name for `is_enabled` on
 * `employee_roles` and five others, and it fails as a picker that filters one of the two.
 *
 * A deactivated function disappears from the assignment picker while existing
 * `employee_job_functions` rows stay intact and readable, and `restore()` brings it back with
 * its history. Hard deletion would orphan those rows.
 */
class JobFunction extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The starting set (`schema.md`). Not a closed list — it grows as the factory, studio,
     * galleria and restaurant are mapped, which is why this is a table and not an enum.
     *
     * ⚠ `Intern` and `Logistic` are deliberately NOT here. `Intern` is an `employment_type`
     * and `Logistic` is a branch (adr/0002). An intern doing media work has job function
     * `Media` and `employment_type = INTERN` — two facts, two fields.
     */
    public const STARTING_SET = [
        'BDO',
        'Admin',
        'Media',
        'Live Host',
        'Operation Crew',
    ];

    protected $fillable = [
        'name',
        'description',
    ];

    /** Every employee currently performing this function, at whichever company. */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeJobFunction::class);
    }
}
