<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The nationality vocabulary — a reference table, not an enum (adr/0013 decision 6).
 *
 * ⚠ NO TENANT SCOPE, AND THE TABLE HAS NO `company_id`. The vocabulary is GROUP-WIDE, the same
 * reasoning as `job_functions` (adr/0003 decision 2). The scope guard test skips this model by
 * there being no column to scope, the same footing as `JobFunction` and `Sequence` — not by an
 * opt-out.
 *
 * ⚠ HR MAY EXTEND THIS ONE, AND `job_functions` STAYS MASTER ADMIN'S. That difference is
 * deliberate and costed in adr/0013 decision 6: the reason is that a hiring must not stall
 * waiting for Master Admin, and the cost is that the structural guarantee against two spellings
 * of one country is gone. The unique index stops `Bangladesh` twice; it does not stop `Myanmar`
 * and `Burma` coexisting. The authorising abilities land with the screen that creates entries.
 *
 * ⚠ DEACTIVATION IS THE SOFT DELETE. There is no `is_active` column and none may be added: two
 * columns for one state is the pattern rejected by name for `is_enabled` on `employee_roles`
 * and five others, and it fails as a picker that filters one of the two. A deactivated
 * nationality disappears from the picker while the employees carrying it keep a valid
 * `nationality_id`, and `restore()` brings it back.
 */
class Nationality extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The starting set, ten values (adr/0013 decision 6).
     *
     * ⚠ A STARTING SET, NOT A CLOSED LIST — that is the whole reason this is a table and not an
     * enum. HR adds to it from the UI as the group hires from somewhere new; a new nationality
     * needs neither a migration nor an edit to this constant.
     *
     * Malaysia is first because it is the common case, not because anything reads position.
     */
    public const STARTING_SET = [
        'Malaysia',
        'Indonesia',
        'Bangladesh',
        'Myanmar',
        'Nepal',
        'India',
        'Pakistan',
        'Vietnam',
        'Philippines',
        'Thailand',
    ];

    protected $fillable = [
        'name',
    ];

    /**
     * Every employee holding this nationality, at whichever company.
     *
     * ⚠ Returns rows through `Employee`'s tenant scope, so it answers "of the employees I may
     * read, which hold this nationality" — never a group-wide count for an account scoped to
     * one subsidiary.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
