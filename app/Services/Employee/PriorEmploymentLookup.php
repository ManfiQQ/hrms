<?php

namespace App\Services\Employee;

use App\Models\Employee;
use App\Models\Scopes\TenantScope;
use App\Support\Employee\PriorEmployment;
use App\Support\Auth\PhoneNumber;
use InvalidArgumentException;

/**
 * *"Has this person worked here before?"* — `adr/0015` decision 5, implemented at its narrowest.
 *
 * ⚠ IT RETURNS AN ANSWER, NOT A BROWSABLE SET. One identifier in, one `PriorEmployment` or null
 * out. There is no name search, no `LIKE`, no pagination and no list, and none of those is a
 * missing feature: a fuzzy or listable version of this turns an existence check into an identity
 * oracle over every archived employee in the group.
 *
 * ⚠ WHAT MAKES THE NARROW SHAPE SAFE IS WHO MAY CALL IT, NOT WHAT IT RETURNS. There is
 * deliberately NO HTTP route for this. An endpoint keyed on an IC that answers with a name lets
 * anybody holding an account probe whether a given IC has ever been employed here. It is called
 * from the registration component, behind the same `EmployeePolicy::create()` gate the form is
 * behind, and any future caller inherits that obligation.
 *
 * ⚠ IT IS NOT A GENERAL READ SCOPE, AND `adr/0015`'s amendment says so. It releases `TenantScope`
 * and soft deletes for one exact-match query returning six fields. It does not widen what any
 * account may read anywhere else, and `EmployeePolicy` is untouched by it.
 *
 * ⚠ WHY IT IS A CLASS AND NOT AN ELOQUENT SCOPE. A scope on `Employee` would return a builder,
 * and a builder returns models — which is the wide shape this exists to avoid. It also carries a
 * business rule (*the most recent prior employment wins*) that belongs in the service layer
 * rather than on the model (`conventions.md` §1).
 */
class PriorEmploymentLookup
{
    /**
     * ⚠ Columns on `employees` matched EXACTLY, plus `users.phone_no` handled separately below.
     *
     * `ic_no` alone would be wrong, and the reason is this workforce: `ic_no` is NULLABLE, and a
     * non-citizen holds `passport_no` instead. The seeded nationalities are Indonesia,
     * Bangladesh, Myanmar, Nepal, India, Pakistan, Vietnam, Philippines and Thailand — so an
     * IC-only key would miss precisely the group most likely to return (`adr/0013` decision 6).
     *
     * `users.phone_no` is included because it is the only one of the three that is **NOT NULL**,
     * which makes it the most reliably present key of all (`adr/0006`, BR-A1).
     */
    private const IDENTITY_COLUMNS = ['ic_no', 'passport_no'];

    /**
     * The most recent ended employment matching this identifier, or null.
     *
     * @param  string  $identifier  an IC, a passport number, or a phone number — exact match only
     *
     * @throws InvalidArgumentException on a blank identifier
     */
    public function find(string $identifier): ?PriorEmployment
    {
        $identifier = trim($identifier);

        // ⚠ THE BLANK GUARD, AND IT PREVENTS A REAL FAILURE RATHER THAN A THEORETICAL ONE.
        //
        // MEASURED on MySQL 8.4.11 through this project's own connection:
        // `->where('ic_no', null)` does NOT compile to `ic_no = NULL`. Laravel compiles it to
        // `where `ic_no` is null`, which MATCHES EVERY PASSPORT-ONLY EMPLOYEE — a large part of
        // this workforce. The first row wins, and the form links the new employee to a stranger's
        // record, setting prior service across employers for two unrelated people.
        //
        // A form that posts an untouched box sends the empty string, which Laravel's
        // ConvertEmptyStringsToNull turns into exactly that null. So the path from "HR left the
        // box empty" to "linked to a random record" is short and needs no mistake by anybody.
        //
        // ⚠ Throwing rather than returning null is deliberate: an empty search is a CALLER BUG,
        // and answering "no prior employment" would hide it behind a plausible result.
        if ($identifier === '') {
            throw new InvalidArgumentException(
                'A prior-employment lookup needs an identifier. An empty one is not a search '
                .'that found nothing: Laravel compiles where(column, null) to IS NULL, which '
                .'matches every employee whose ic_no is empty — and this lookup would then link '
                .'a new employee to a stranger. Guard the caller instead of searching (adr/0015 '
                .'decision 5).'
            );
        }

        $candidate = Employee::query()
            // ⚠ BOTH SCOPES RELEASED, the same carve-out AccountExpiry and ReadScopeResolver
            // take (conventions.md §2). A prior record is routinely soft-deleted (§5.2 archives,
            // never hard-deletes) and belongs to whoever employed them THEN, which may be another
            // group entity. A scoped or non-trashed read finds nothing and reports "no prior
            // employment" — a false negative, after which the unique index refuses the IC anyway.
            // That is the two-contradictory-answers failure recorded in conventions.md §9.
            ->withoutGlobalScope(TenantScope::class)
            ->withTrashed()

            // ⚠ TERMINAL ONLY. A rejoin continues from an employment that ENDED (BR-2). A LIVE
            // record holding this identity is not a predecessor — it is a duplicate person, and
            // the unique index is what refuses that, with a message naming the field. Returning
            // it here would offer HR a link that `CreateEmployee::supersedePrior()` then refuses
            // (`adr/0015` decision 6), which is a worse error than not finding it.
            ->whereIn('staff_status', Employee::TERMINAL_STATUSES)

            ->where(function ($query) use ($identifier) {
                foreach (self::IDENTITY_COLUMNS as $column) {
                    // ⚠ whereNotNull BESIDE THE COMPARISON, NOT INSTEAD OF THE BLANK GUARD ABOVE.
                    // The two catch different things. The guard stops an empty search happening;
                    // this stops a NULL-holding row being a CANDIDATE under any comparison a
                    // future edit might introduce — including `where(column, null)`, which
                    // compiles to IS NULL and would otherwise match every row missing that
                    // column. With both present the clause reads `is not null and is null` and
                    // returns nothing, which is the safe outcome rather than a random match.
                    $query->orWhere(fn ($q) => $q->whereNotNull($column)->where($column, $identifier));
                }

                // The login username, on the ACCOUNT (adr/0006). Normalised before comparing,
                // because it is normalised before storing — an un-normalised comparison misses
                // the row and reports no prior employment (BR-A1's one-normaliser rule).
                $normalised = PhoneNumber::normalise($identifier);

                if (PhoneNumber::isValid($normalised)) {
                    $query->orWhereHas('user', fn ($q) => $q->where('phone_no', $normalised));
                }
            })

            // ⚠ THE MOST RECENT PRIOR EMPLOYMENT WINS, and this rule is required rather than a
            // tidiness choice. Several records can match one identifier: every superseded row
            // indexes to NULL in the functional unique indexes, so somebody who left and returned
            // twice has two superseded records carrying the SAME ic_no. `CreateEmployee` links
            // each new record to the one immediately before it, so the answer must be the latest.
            //
            // ⚠ Ordered on the RECORD, not on the ledger. Reading the terminal effective_date
            // would need a join or a subquery per candidate, and `join_date` plus `id` gives the
            // same ordering for records created in sequence. ⚠ The cost: two employments whose
            // join_date is null order by id alone — insertion order, which is the same thing here
            // because the sequence never rewinds.
            ->orderByDesc('join_date')
            ->orderByDesc('id')

            ->first();

        return $candidate === null ? null : $this->answer($candidate);
    }

    /**
     * ⚠ THE NARROWING HAPPENS HERE, and it is the whole point of the return type. Six fields are
     * copied off the model and the model is dropped. A caller never receives the row, so it
     * cannot read a thirteenth column from it by accident.
     */
    private function answer(Employee $employee): PriorEmployment
    {
        return new PriorEmployment(
            employeeId: $employee->id,
            fullName: $employee->full_name,
            employeeNo: $employee->employee_no,

            // ⚠ Loaded WITHOUT TenantScope, like the employee itself. The prior employer may be a
            // company this account cannot otherwise read — which is exactly why the field is
            // returned. See PriorEmployment's note: linking is an act, and an HR who links an AIM
            // record without seeing "AIM" is acting across a tenant boundary blind.
            companyName: $employee->company()->withoutGlobalScope(TenantScope::class)->value('name') ?? '',

            servedFrom: $employee->join_date,
            servedTo: $this->terminalEffectiveDate($employee),
        );
    }

    /**
     * The `effective_date` of the most recent terminal status change — the last working day.
     *
     * ⚠ THE SAME READ `AccountExpiry` MAKES, AND FOR THE SAME REASONS. `reorder()` rather than
     * `orderByDesc()`, because `Employee::statusHistory()` already applies an ascending
     * `orderBy('effective_date')` for the history tab and an appended clause does not replace it
     * — the first ORDER BY wins, so `value()` would quietly return the OLDEST terminal row.
     *
     * ⚠ Returns null when a terminal record has no ledger row. That state is real rather than
     * hypothetical — `AccountExpiry` documents and tests it — so `servedTo` is nullable and
     * `PriorEmployment::hasServicePeriod()` exists to ask about it.
     */
    private function terminalEffectiveDate(Employee $employee): ?\Carbon\CarbonInterface
    {
        return $employee->statusHistory()
            ->where('change_type', 'STAFF_STATUS')
            ->whereIn('new_value', Employee::TERMINAL_STATUSES)
            ->reorder('effective_date', 'desc')
            ->orderByDesc('id')
            ->value('effective_date');
    }
}
