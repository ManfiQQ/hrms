<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What a person does, and where — the assignment side of the job-function vocabulary.
 *
 * ⚠ A COMPANY-REFERENCE row, not a tenant-marked one, and the difference decides a transfer.
 * `company_id` says where the person performs this function; it does not say which tenant owns
 * the row. So the row is left ENTIRELY UNTOUCHED when `employees.company_id` changes
 * (adr/0003 decision 7) — cascading it would corrupt the record rather than merely hide it.
 *
 * Job function is distinct from authority, and merging them would force the approval engine to
 * answer questions that should not exist — "can a Live Host approve a leave request?"
 * (adr/0003 decision 2). Authority is `employee_roles`.
 */
class EmployeeJobFunction extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Deliberately carries neither TenantScope nor SharedTenantScope — the same declaration,
     * for the same reason, as `EmployeeRole`.
     *
     * `company_id` here is a real reference to which company the row is ABOUT. Scoping it to
     * the reader's companies would filter assignment records by who is looking, which is a
     * different question from the one the column answers.
     *
     * ⚠ The exemption is DECLARED, never expressed by silence, so that "deliberately unscoped"
     * and "someone forgot" stay distinguishable — that distinction is the entire value of the
     * guard test (adr/0005 decision 6). Adding a third exemption is a visible change to
     * TenantScopeGuardTest, which names them individually for exactly this reason.
     */
    public const TENANT_SCOPE_EXEMPT = 'company_id is a company reference, not a tenant marker (adr/0003 decision 7)';

    protected $fillable = [
        'employee_id',
        'company_id',
        'job_function_id',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The company where the function is performed — not necessarily the payroll employer. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * ⚠ Deactivated functions are soft-deleted, so this returns null once one is withdrawn.
     * Use `withTrashed()` when rendering an employee's history: the assignment stays true and
     * readable after the function leaves the picker.
     */
    public function jobFunction(): BelongsTo
    {
        return $this->belongsTo(JobFunction::class);
    }
}
