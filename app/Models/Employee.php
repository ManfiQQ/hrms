<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    /** Narrows every query to the account's read scope (adr/0005 decision 2). */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    /**
     * ⚠ `company_id` is deliberately absent from this list.
     *
     * It is the payroll and legal employer and it bounds read scope, so it must never be
     * settable from request input. employee-master.spec.md §8 test 2 is a mass-assignment
     * probe asserting exactly that. Set it explicitly in a service or action, never by
     * filling a request array.
     */
    protected $fillable = [
        'employee_no',
        'previous_employee_id',
        'full_name',
        'nickname',
        'email',
        'branch_id',
        'department_id',
        'position_id',
        'fingerprint_id',
        'level',
        'employment_type',
        'staff_status',
        'join_date',
        'probation_end_date',
        'confirmation_date',
        'direct_supervisor_id',
        'manager_id',
        'attendance_type',
        'work_start_time',
        'work_end_time',
        'ot_after_time',
        'working_days',
        'offday',
        'hours_enabled',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'join_date' => 'date',
            'probation_end_date' => 'date',
            'confirmation_date' => 'date',
            'working_days' => 'array',
            'offday' => 'array',
            'hours_enabled' => 'boolean',
        ];
    }

    /**
     * The payroll and legal employer — that meaning only.
     *
     * It does not answer "what authority does this person have"; roles() does
     * (adr/0003 decision 6). It BOUNDS read scope through the employer's position in the
     * company hierarchy, but never GRANTS visibility — roles do (adr/0004 decision 1).
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Org assignment. Independent of company_id and not required to match it — an employee
     * may sit in a shared branch or department, or one belonging to a different company.
     * Validation must not reject that (adr/0002 decision 2).
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * Currently held authority roles, per company.
     *
     * Revoked rows are excluded by EmployeeRole's default global scope. "What authority
     * does this person have?" has no answer until a company is named, so callers must
     * filter by company — a permission function without a company_id argument is a bug
     * (adr/0003 decision 1).
     */
    public function roles(): HasMany
    {
        return $this->hasMany(EmployeeRole::class);
    }

    /**
     * The append-only employment ledger for this person.
     *
     * ⚠ TENANT SCOPE IS RELEASED HERE, DELIBERATELY, AND THIS LINE IS THE CARVE-OUT
     * (adr/0003 decision 7, conventions.md §2's second carve-out).
     *
     * `employee_status_history.company_id` is a FROZEN historical fact — the employer at the
     * moment each change happened — and it is never cascaded on a company transfer. So after
     * a transfer every pre-transfer row carries the OLD company id, the ordinary scope
     * filters them out, and **this employee's history appears to begin on the transfer
     * date**. Fewer rows, no exception, nothing to notice: it reads as somebody who joined
     * recently.
     *
     * Releasing the scope is safe because permission was already decided one level up: **if
     * the caller may read this employee, they may read this employee's history.** Filtering
     * again per row adds no security and breaks the record.
     *
     * ⚠ This release is scoped to THIS relationship. `EmployeeStatusHistory` queried
     * directly stays tenant-scoped in full, so reporting reads — "how many promotions did
     * TURSENIA make this year" — remain TURSENIA's. Both directions are tested; asserting
     * only this one turns a narrow carve-out into a blanket bypass.
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(EmployeeStatusHistory::class)
            ->withoutGlobalScope(TenantScope::class)
            ->orderBy('effective_date');
    }

    /**
     * The rejoiner link. RESIGNED and TERMINATED are terminal, so a returning employee gets
     * a new record with a new employee_no — never a reactivated one — and this is the only
     * thread back (adr/0003 decision 9, business-rules.md BR-2).
     */
    public function previousEmployee(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_employee_id');
    }

    /** Two-tier reporting, confirmed from the legacy Staff Master template. */
    public function directSupervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'direct_supervisor_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /**
     * The login account. Created in the same transaction as this record, because every
     * employee needs one to verify their own attendance data (adr/0004 decision 7).
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * RESIGNED and TERMINATED are terminal and trigger the account freeze
     * (adr/0004 decision 5). There is no reactivation, by anyone.
     */
    public function hasTerminalStatus(): bool
    {
        return in_array($this->staff_status, ['RESIGNED', 'TERMINATED'], true);
    }
}
