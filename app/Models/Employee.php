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

        // Identity and statutory fields — adr/0013 decision 1.
        //
        // ⚠ There is no `personal_phone` here and none on the table. `users.phone_no` is the
        // personal number AND the login username (adr/0006); a second would be two numbers for
        // one person, and the Personal tab displays the account's through user().
        //
        // ⚠ `bank_name` and `bank_account_no` are where salary is SENT, not how much. Employee
        // Master holds no salary data (§10 decision 3, adr/0003 decision 5), and nothing here
        // may be read as an opening for some.
        'ic_no',
        'passport_no',
        'permit_expiry',
        'date_of_birth',
        'gender',
        'nationality_id',
        'address',
        'epf_no',
        'socso_no',
        'tax_no',
        'bank_name',
        'bank_account_no',

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
            // adr/0013 decision 1. `permit_expiry` is cast so the expired-permit flag compares
            // dates rather than strings; `date_of_birth` so age — which SOCSO's rate turns on
            // at 60 — is derived rather than parsed at each call site.
            'permit_expiry' => 'date',
            'date_of_birth' => 'date',

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
     * Citizenship — a group-wide reference table, not an enum (adr/0013 decisions 1 and 6).
     *
     * ⚠ `withTrashed()`, AND WITHOUT IT THIS RETURNS NULL FOR A WITHDRAWN NATIONALITY.
     * Deactivation is the soft delete, so an employee hired under `Myanmar` keeps pointing at
     * that row after HR withdraws it from the picker — the FK stays valid and `nationality_id`
     * is NOT NULL, but the ordinary relationship would filter the parent out and the Personal
     * tab would render a blank where a country belongs. Withdrawing a nationality must stop it
     * being CHOSEN, never stop it being DISPLAYED on the records that already carry it.
     */
    public function nationality(): BelongsTo
    {
        return $this->belongsTo(Nationality::class)->withTrashed();
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
     * What this person does, per company — distinct from what they may approve.
     *
     * ⚠ NOT tenant-scoped by the reader, because `employee_job_functions.company_id` is a
     * company REFERENCE rather than a tenant marker (adr/0003 decision 7). That is what makes
     * BR-12's "Also serving at" line possible: a person employed by AHS performing Media at
     * AIM shows both, and a scope here would hide the second by returning fewer rows.
     */
    public function jobFunctions(): HasMany
    {
        return $this->hasMany(EmployeeJobFunction::class);
    }

    /**
     * The four DESCRIPTIVE child collections — §6.2's Family, Education, Employment History
     * and Documents tabs.
     *
     * ⚠ These keep their tenant scope, unlike statusHistory() above, and the asymmetry is the
     * point rather than an inconsistency. That relationship releases the scope because its
     * rows are FROZEN under a former employer and would otherwise vanish after a transfer.
     * These four CASCADE on a transfer, so their `company_id` always already matches the
     * employee's — there is nothing for a release to rescue, and releasing anyway would widen
     * access for no benefit.
     *
     * Read permission is decided per TAB, not per record: a Supervisor reading an employee's
     * Employment tab may not read their Family tab (EmployeePolicy, adr/0004 decision 8).
     */
    public function familyMembers(): HasMany
    {
        return $this->hasMany(EmployeeFamilyMember::class);
    }

    public function educationHistory(): HasMany
    {
        return $this->hasMany(EmployeeEducationHistory::class);
    }

    public function employmentHistory(): HasMany
    {
        return $this->hasMany(EmployeeEmploymentHistory::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /**
     * The emergency contact, surfaced on the EMPLOYMENT tab rather than behind Family —
     * §6.2's deliberate exception (adr/0004 decision 8).
     *
     * A supervisor is likely the first person present at an accident and needs the number
     * without being handed the whole family record. Name and number only; the rest of the row
     * stays behind the Family tab they may not read.
     */
    public function emergencyContacts(): HasMany
    {
        return $this->familyMembers()->where('is_emergency_contact', true);
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
