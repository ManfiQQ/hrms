<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Policies\EmployeePolicy;
use App\Services\Auth\ReadScopeResolver;
use App\Services\Auth\RoleChecker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The three display enums, in the order `schema.md` declares them.
     *
     * ⚠ WRITTEN ONCE, HERE. They were previously literal arrays inside `EmployeeStoreRequest`
     * and `EmployeeUpdateRequest`, and the list screen would have been a third copy — three
     * places to edit when a value is added, and nothing to catch the one that is missed.
     */
    public const STAFF_STATUSES = ['PROBATION', 'ACTIVE', 'CONFIRMED', 'SUSPENDED', 'RESIGNED', 'TERMINATED'];

    public const EMPLOYMENT_TYPES = ['FULL-TIME', 'PART-TIME', 'CONTRACT', 'INTERN', 'FREELANCE'];

    /** Display only — never an authorization or routing input (BR-9, `adr/0001` decision 1). */
    public const LEVELS = ['STAFF', 'SUPERVISOR', 'MANAGER', 'HOD'];

    /**
     * Terminal statuses (BR-2). Reaching one freezes the account and revokes every
     * `employee_roles` row in the same transaction (`adr/0004` decision 5), and there is no
     * way back — a returning employee gets a new record.
     */
    public const TERMINAL_STATUSES = ['RESIGNED', 'TERMINATED'];

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
     * The employees this account may see in a LIST — `adr/0011`, spec §5.4.
     *
     * ⚠ THE SECOND FORM OF A RULE `EmployeePolicy::view()` ALREADY EXPRESSES, and the two must
     * agree. `view()` answers *(actor, subject) → bool*; this answers *(actor) → set*. They
     * cannot share an implementation — one is PHP over a loaded row, the other is SQL over
     * rows nobody has loaded — so `EmployeeListVisibilityTest` compares their outcomes across
     * a population. Read that test before changing either.
     *
     * ⚠ A LOCAL SCOPE, CALLED EXPLICITLY, AND NEVER A GLOBAL ONE. `TenantScope` is global
     * because tenancy is a property of the table: every read of `employees` is bounded by it,
     * including `CreateEmployee`, `TransferCompany`, the audit reader and the seeders. The
     * supervisory narrowing is not a property of the table — it is the question ONE SCREEN
     * asks — and a global version would answer it for every query in the system.
     *
     * That distinction is the whole safety argument. **A scope that must be called cannot
     * narrow a query that does not call it**, so HR cannot silently lose rows: a wrong filter
     * here returns fewer rows rather than erroring (`adr/0002`), and the blast radius of that
     * failure is bounded to the callers who opted in.
     *
     * ⚠ THE ACTOR'S OWN RECORD IS ALWAYS INCLUDED, and it is not a courtesy. `viewTab()`
     * returns true for the actor's own record BEFORE any role check, so a scope that omitted
     * it would disagree with the policy and the guard would go red. A supervisor with no
     * subordinates therefore sees exactly one row — themselves — and that is `adr/0011`
     * decision 4 being visible rather than a defect: an employee whose BR-8 columns are empty
     * is read by nobody below `HR`, and an empty list is the signal that they were never
     * filled.
     */
    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        // FULL and VIEW_ONLY hold no employee record, so no role check could answer for them.
        // ReadScopeResolver has already given them every company, and TenantScope has already
        // applied it — there is nothing further to narrow (adr/0004 decision 2).
        if (in_array($actor->system_access, ['FULL', 'VIEW_ONLY'], true)) {
            return;
        }

        $roles = app(RoleChecker::class);

        // ⚠ The administrative tier reads every record INSIDE THE READ SCOPE, which
        // TenantScope has already applied to this query. The role is checked across the scope
        // companies exactly as EmployeePolicy::hasAnyRoleInScope() does — a subsidiary-employed
        // HR holds the role at one company and reads that company, and the two facts are
        // resolved separately.
        foreach (app(ReadScopeResolver::class)->resolve($actor) as $companyId) {
            if ($roles->hasAnyRole($actor, EmployeePolicy::ADMINISTRATIVE_ROLES, $companyId)) {
                return;
            }
        }

        $actorEmployee = $actor->employee_id === null
            ? null
            : self::withoutGlobalScope(TenantScope::class)->find($actor->employee_id);

        // Unreachable in practice — ReadScopeResolver throws OrphanedAccountException for a
        // STANDARD account with no employee, and TenantScope calls it before this runs. It
        // mirrors viewTab()'s own null branch so the two stay in step if that ever changes.
        if ($actorEmployee === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        // No supervisory role at their own company: their own record and nothing else. There
        // is no STAFF role value — absence IS the staff state (adr/0003 decision 1).
        if (! $roles->hasAnyRole($actor, EmployeePolicy::SUPERVISORY_ROLES, $actorEmployee->company_id)) {
            $query->whereKey($actorEmployee->id);

            return;
        }

        // ⚠ THE REPORTING LINE, ONE LEVEL, IN ONE DIRECTION (adr/0011 decisions 1 and 2). The
        // SUBJECT's two BR-8 columns are compared against the ACTOR's employee id — never the
        // reverse, which would list an employee's own supervisor. Both columns are nullable,
        // so an employee who reports to nobody is excluded without a special case.
        //
        // The company bound is `employees.company_id` on both sides, never
        // `employee_roles.company_id`: the role row says where authority applies, the employee
        // row says who employs the person (adr/0002 decision 4, adr/0003 decision 6). A shared
        // department is where this is most likely to be got wrong, because the two employees
        // are visibly colleagues.
        $query->where(function (Builder $visible) use ($actorEmployee) {
            $visible
                ->where(function (Builder $reporting) use ($actorEmployee) {
                    $reporting
                        ->where($this->qualifyColumn('company_id'), $actorEmployee->company_id)
                        ->where(function (Builder $line) use ($actorEmployee) {
                            $line
                                ->where($this->qualifyColumn('direct_supervisor_id'), $actorEmployee->id)
                                ->orWhere($this->qualifyColumn('manager_id'), $actorEmployee->id);
                        });
                })
                ->orWhere($this->qualifyColumn('id'), $actorEmployee->id);
        });
    }

    /**
     * One search term across the four searchable fields — spec §5.4.
     *
     * ⚠ THE GROUP IS PARENTHESISED — AND MEASURING THE BREAK SHOWED THIS IS DEFENCE IN DEPTH,
     * NOT THE BOUNDARY ITSELF. Four ORed conditions left at the top level would bind looser
     * than the ANDed visibility conditions before them, and the search box would become a way
     * around the rule — returning MORE rows rather than fewer, the failure direction nobody
     * watches for.
     *
     * Removing this closure does NOT produce that bug, and the reason matters: **Laravel wraps
     * every constraint added inside a LOCAL SCOPE in its own group** (`addNewWheresWithinGroup`),
     * so the parentheses survive without it. Verified by reading the generated SQL, after the
     * deliberate break came back green.
     *
     * What that protection depends on is the search living in a scope AT ALL. Inline the same
     * four `orWhere`s in the Livewire component and the grouping disappears with them —
     * `EmployeeListTest`'s leak test goes red on exactly that change, and it is the real reason
     * §5.4 says the query lives in a model scope rather than in the caller.
     *
     * ⚠ SUBSTRING, NOT PREFIX, AND THE COST IS REAL. `full_name` holds full Malay names —
     * *Nurul Aina binti Rahman* — and HR usually remembers the last name, so `LIKE 'rahman%'`
     * would find nothing while `LIKE '%rahman%'` finds the person. **A leading wildcard cannot
     * use an index**, so this is a full table scan; there is no index on `full_name` today
     * either way, and the scale is hundreds of rows. **At a much larger scale this is a
     * decision to revisit**, not a permanent answer.
     *
     * ⚠ `phone_no` IS NOT SEARCHABLE HERE and must not be added: the employee record holds no
     * number at all (`adr/0006`). Searching by number means searching ACCOUNTS, which is the
     * account management screen's question.
     */
    public function scopeMatchingSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        // `%` and `_` are wildcards to LIKE, so a search for "50%" would otherwise match
        // everything beginning with 50 — and a search for "%" would match every row.
        $pattern = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $match) use ($pattern) {
            $match
                ->where($this->qualifyColumn('employee_no'), 'like', $pattern)
                ->orWhere($this->qualifyColumn('full_name'), 'like', $pattern)
                ->orWhere($this->qualifyColumn('nickname'), 'like', $pattern)
                ->orWhere($this->qualifyColumn('email'), 'like', $pattern);
        });
    }

    /**
     * The seven filters §5.4 names, each applied only when chosen.
     *
     * ⚠ THESE ARE FILTERS THE READER PICKS, NEVER A BOUNDARY THE SYSTEM IMPOSES. `department`
     * in particular reads like the old supervisory rule and is not: the bound is
     * `visibleTo()`, which is applied separately and cannot be turned off from a form. A
     * filter that arrived empty leaves the query alone, so an account that clears every filter
     * still sees only what it may see.
     *
     * ⚠ THE KEYS ARE COLUMN NAMES AND MUST COME FROM THE CALLER, NEVER FROM REQUEST INPUT.
     * The caller names the seven columns it supports; only the VALUES are user input. Passing
     * a request array straight in would let a crafted key filter on any column on the table —
     * `ic_no`, `bank_account_no` — turning a list into an oracle that answers whether a value
     * exists.
     *
     * @param  array<string, mixed>  $filters  keyed by column; null or '' means "not chosen"
     */
    public function scopeFilteredBy(Builder $query, array $filters): void
    {
        foreach ($filters as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $query->where($this->qualifyColumn($column), $value);
        }
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
        return in_array($this->staff_status, self::TERMINAL_STATUSES, true);
    }
}
