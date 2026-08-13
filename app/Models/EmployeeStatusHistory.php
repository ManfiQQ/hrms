<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The append-only employment ledger — adr/0003 decision 8.
 *
 * Every status, grade, position and department change is a new row. A correction is a new
 * row too, never an edit: a ledger that can be rewritten after the fact cannot answer "when
 * did this employee move from Grade C to D" with any authority.
 *
 * ⚠ THIS IS AN EVENT TABLE, and its `company_id` is a FROZEN HISTORICAL FACT — the employer
 * at the moment the change happened. It is never cascaded on a company transfer
 * (adr/0003 decision 7, conventions.md §2's second carve-out).
 *
 * ⚠ WHICH CREATES THE FAILURE THIS MODEL EXISTS TO PREVENT. After a transfer every
 * pre-transfer row still carries the OLD company_id, so the ordinary tenant scope filters
 * them out and the employee's history tab appears to BEGIN ON THE TRANSFER DATE. Fewer
 * rows, no exception, no failing happy-path test — it looks like an employee who joined
 * recently.
 *
 * The scope is therefore RELEASED when these rows are read through the employee
 * relationship (Employee::statusHistory()), because permission was already decided one level
 * up: **if the user may read this employee, they may read this employee's history.**
 * Re-filtering row by row adds no security whatsoever and breaks the record.
 *
 * Queried DIRECTLY, for reporting, the scope applies in full — so "how many promotions did
 * TURSENIA make this year" stays TURSENIA's. Both directions are tested, as conventions.md
 * §2 requires; testing only the first turns a narrow carve-out into a blanket bypass.
 */
class EmployeeStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'employee_status_history';

    /**
     * The five change types, and only five.
     *
     * ⚠ EMPLOYER was added on 2026-08-13 by adr/0010 — a company transfer is a ledger event.
     * It is named for the FIELD, as the other four are: `employees.company_id` means "the
     * payroll and legal employer — that meaning only". `COMPANY` was rejected because a row
     * reading change_type = COMPANY beside its own company_id column uses one word for two
     * different things.
     *
     * ⚠ CORE_ROLE is deliberately absent and must not be added. Role history lives in
     * `employee_roles`, which records every grant and revocation with its date, actor and
     * reason; writing the same event here would create two records of one fact that can
     * disagree (adr/0003 decision 8). A fifth value is not an invitation for a sixth.
     */
    public const CHANGE_TYPES = [
        'STAFF_STATUS',
        'POSITION',
        'DEPARTMENT',
        'LEVEL',
        'EMPLOYER',
    ];

    /** Append-only: created_at alone, managed by Eloquent; updated_at does not exist. */
    public const UPDATED_AT = null;

    /**
     * Narrows to the account's read scope — the ordinary business-table default
     * (adr/0005 decision 2). Released only through Employee::statusHistory().
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        // Append-only, enforced on the model as well as by the absence of an edit path. The
        // absence of a path is not a guarantee: an ->update() written anywhere would
        // otherwise succeed silently, and the row it rewrote would be the one somebody later
        // relies on to prove when a promotion took effect.
        static::updating(function (): never {
            throw new RuntimeException('employee_status_history is append-only: a correction is a new row (adr/0003 decision 8).');
        });

        static::deleting(function (): never {
            throw new RuntimeException('employee_status_history is append-only: rows are inserted, never deleted (adr/0003 decision 8).');
        });
    }

    protected $fillable = [
        'company_id',
        'employee_id',
        'change_type',
        'old_value',
        'new_value',
        'old_label',
        'new_label',
        'effective_date',
        'reason',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The employer AT THE TIME of the change — not necessarily the employee's company today.
     * Frozen on a transfer, deliberately (adr/0003 decision 7).
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
