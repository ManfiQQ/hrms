<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\User;
use App\Services\Employee\PriorEmploymentLookup;
use App\Support\Employee\PriorEmployment;

/**
 * `adr/0015` decision 5 — *"has this person worked here before?"*, at its narrowest.
 *
 * ⚠ THE SHAPE UNDER TEST IS AN ANSWER, NOT A BROWSABLE SET. What these tests protect is as much
 * what the lookup CANNOT do as what it can: no name search, no partial match, no list, and six
 * fields out. A later change that returns an `Employee`, accepts a `LIKE`, or grows a route turns
 * an existence check into an identity oracle over every archived employee in the group.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->masterAdmin()->create());

    $this->ahs = Company::factory()->create(['code' => 'AHS', 'name' => 'AL HADDAD SUCCESS SDN BHD']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM', 'name' => 'AL HADDAD INTEGRATED MARKETING']);

    $this->lookup = app(PriorEmploymentLookup::class);
});

/** An ended employment, with the ledger row that gives it a last working day. */
function endedEmployment(Company $company, array $attributes = [], string $lastDay = '2024-06-30'): Employee
{
    $employee = Employee::factory()->forCompany($company)->resigned()->create($attributes);

    EmployeeStatusHistory::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'change_type' => 'STAFF_STATUS',
        'old_value' => 'ACTIVE',
        'new_value' => 'RESIGNED',
        'effective_date' => $lastDay,
    ]);

    return $employee;
}

// ─── What it finds ──────────────────────────────────────────────────────────────────────────

it('answers on an exact IC match', function () {
    $prior = endedEmployment($this->aim, [
        'ic_no' => '900101145501',
        'full_name' => 'Siti Aminah binti Yusof',
        'join_date' => '2020-02-01',
    ]);

    $answer = $this->lookup->find('900101145501');

    expect($answer)->toBeInstanceOf(PriorEmployment::class)
        ->and($answer->employeeId)->toBe($prior->id)
        ->and($answer->fullName)->toBe('Siti Aminah binti Yusof')
        ->and($answer->employeeNo)->toBe($prior->employee_no)
        ->and($answer->servedFrom->toDateString())->toBe('2020-02-01')
        ->and($answer->servedTo->toDateString())->toBe('2024-06-30');
});

/**
 * ⚠ `ic_no` ALONE WOULD BE THE WRONG KEY FOR THIS WORKFORCE. It is nullable, and a non-citizen
 * holds `passport_no` instead — the seeded nationalities are Indonesia, Bangladesh, Myanmar,
 * Nepal, India, Pakistan, Vietnam, Philippines, Thailand. An IC-only lookup would miss precisely
 * the group most likely to return.
 */
it('answers on an exact passport match for an employee with no IC', function () {
    $prior = endedEmployment($this->aim, ['ic_no' => null, 'passport_no' => 'A12345678']);

    expect($this->lookup->find('A12345678')?->employeeId)->toBe($prior->id);
});

/**
 * ⚠ THE MOST RELIABLY PRESENT KEY OF THE THREE. `users.phone_no` is NOT NULL, where both identity
 * columns are nullable — and BR-A1 makes it the login username, so every employee has one.
 */
it('answers on the login phone number, normalised before comparing', function () {
    $prior = endedEmployment($this->aim, ['ic_no' => '770707077777']);
    User::factory()->forEmployee($prior)->create(['phone_no' => '0198887766']);

    // ⚠ Every written form of one number must find it — the stored value is normalised, so an
    // un-normalised comparison misses the row and reports no prior employment (BR-A1).
    foreach (['0198887766', '019-888 7766', '+60198887766', '60198887766'] as $written) {
        expect($this->lookup->find($written)?->employeeId)->toBe($prior->id);
    }
});

/**
 * ⚠ A PRIOR RECORD IS ROUTINELY SOFT-DELETED (§5.2 archives, never hard-deletes) AND MAY SIT UNDER
 * A FORMER EMPLOYER. Both scopes are released for exactly this. Without either release the lookup
 * reports "no prior employment" and the unique index then refuses the IC anyway — the
 * two-contradictory-answers failure in `conventions.md` §9.
 */
it('finds a prior record that is soft-deleted and under another company', function () {
    $prior = endedEmployment($this->aim, ['ic_no' => '880202101234']);
    $prior->delete();

    $answer = $this->lookup->find('880202101234');

    expect($answer?->employeeId)->toBe($prior->id)
        ->and($answer->companyName)->toBe('AL HADDAD INTEGRATED MARKETING');
});

// ─── What it refuses ────────────────────────────────────────────────────────────────────────

/**
 * ⚠ EXACT MATCH ONLY. A prefix, a suffix or a dashed form must find nothing — a fuzzy lookup is
 * how this stops being an existence check and becomes a searchable index of archived staff.
 *
 * ⚠ The dashed case is also the live cost recorded in `conventions.md` §9: `ic_no` is not
 * normalised, so a legacy row typed with dashes is invisible to this lookup. That is a FALSE
 * NEGATIVE, and it is the exposure the normalisation ADR still owns.
 */
it('matches exactly and never partially', function () {
    endedEmployment($this->aim, ['ic_no' => '900101145501']);

    foreach (['9001011455', '900101145501X', '900101-14-5501', '90010114550'] as $near) {
        expect($this->lookup->find($near))->toBeNull();
    }
});

/**
 * ⚠ A LIVE RECORD IS NOT A PREDECESSOR. A rejoin continues from an employment that ENDED (BR-2);
 * a live record holding this identity is a duplicate person, which the unique index refuses with
 * a message naming the field. Offering it here would hand HR a link that
 * `CreateEmployee::supersedePrior()` then refuses (`adr/0015` decision 6).
 */
it('ignores a live employment holding the same identity', function () {
    Employee::factory()->forCompany($this->aim)->create([
        'ic_no' => '660606106666',
        'staff_status' => 'ACTIVE',
    ]);

    expect($this->lookup->find('660606106666'))->toBeNull();
});

/**
 * ⚠ THE BLANK GUARD, AND THE FAILURE IT PREVENTS IS MEASURED RATHER THAN IMAGINED.
 * `->where('ic_no', null)` does NOT compile to `ic_no = NULL` — Laravel compiles it to
 * `where ic_no is null`, which matches EVERY PASSPORT-ONLY EMPLOYEE. A form posting an untouched
 * box sends an empty string, which ConvertEmptyStringsToNull turns into that null. So the path
 * from "HR left the box empty" to "linked to a stranger's record" needs no mistake by anybody.
 *
 * ⚠ It throws rather than answering null, because an empty search is a CALLER BUG and
 * "no prior employment" would hide it behind a plausible result.
 */
it('refuses a blank identifier instead of searching', function () {
    // Two passport-only employees — the rows an IS NULL comparison would sweep up.
    endedEmployment($this->aim, ['ic_no' => null, 'passport_no' => 'B11111111']);
    endedEmployment($this->ahs, ['ic_no' => null, 'passport_no' => 'C22222222']);

    foreach (['', '   '] as $blank) {
        expect(fn () => $this->lookup->find($blank))
            ->toThrow(InvalidArgumentException::class, 'needs an identifier');
    }
});

/**
 * ⚠ AND A NULL-HOLDING ROW IS NEVER A CANDIDATE even for a non-blank identifier that no row
 * holds. This is what `whereNotNull` beside each comparison buys: the two guards catch different
 * things, and this one survives a future edit that reintroduces `where(column, $maybeNull)`.
 */
it('never answers with a record whose identity columns are empty', function () {
    endedEmployment($this->aim, ['ic_no' => null, 'passport_no' => null]);

    expect($this->lookup->find('999999999999'))->toBeNull();
});

// ─── Which one, when several match ──────────────────────────────────────────────────────────

/**
 * ⚠ SEVERAL RECORDS CAN MATCH ONE IDENTIFIER, AND THIS IS NOT AN EDGE CASE. The functional unique
 * indexes constrain LIVE rows only, so every superseded row indexes to NULL and they may share an
 * `ic_no` freely — somebody who left and returned twice has two superseded records with the same
 * IC and the same NAME. Name cannot separate them; the dates can, and the rule is that the most
 * recent prior employment wins, because `CreateEmployee` links each record to the one immediately
 * before it.
 */
it('answers with the most recent prior employment when several match', function () {
    $first = endedEmployment($this->aim, [
        'ic_no' => '550505105555',
        'full_name' => 'Ahmad bin Ismail',
        'join_date' => '2014-01-01',
    ], '2017-12-31');

    // ⚠ TERMINAL IS NOT SUPERSEDED, AND THE FIXTURE FAILS WITHOUT THIS LINE. The functional
    // index constrains rows where `superseded_at IS NULL`, so a terminal record that nothing has
    // replaced still competes for the IC — the second insert below dies on
    // `employees_ic_no_live_unique`. In production `CreateEmployee::supersedePrior()` sets this
    // as the second registration's first act; here the fixture stands in for it, which is also
    // the honest picture of the state this lookup meets: a chain of prior employments where every
    // record but the last has been superseded.
    $first->superseded_at = now()->subYears(6);
    $first->save();

    $second = endedEmployment($this->ahs, [
        'ic_no' => '550505105555',
        'full_name' => 'Ahmad bin Ismail',
        'join_date' => '2019-03-01',
    ], '2023-08-31');

    $answer = $this->lookup->find('550505105555');

    expect($answer->employeeId)->toBe($second->id)
        ->and($answer->employeeId)->not->toBe($first->id)
        ->and($answer->servedFrom->toDateString())->toBe('2019-03-01');
});

// ─── The shape of the answer ────────────────────────────────────────────────────────────────

/**
 * ⚠ THE NARROWING IS THE FEATURE. Six public properties, and nothing that could carry a
 * thirteenth column. A future change returning an `Employee` would pass every identity and
 * statutory field `adr/0014` tiers by role through a lookup that answers one question.
 */
it('returns six fields and no way to reach a seventh', function () {
    $prior = endedEmployment($this->aim, ['ic_no' => '440404104444', 'epf_no' => 'EPF-SECRET']);

    $answer = $this->lookup->find('440404104444');

    expect(array_keys(get_object_vars($answer)))->toBe([
        'employeeId', 'fullName', 'employeeNo', 'companyName', 'servedFrom', 'servedTo',
    ]);

    // Nothing on the answer exposes the record it came from.
    expect(json_encode($answer))->not->toContain('EPF-SECRET');
});

/**
 * ⚠ `companyName` IS RETURNED DELIBERATELY. Removing it was argued and withdrawn on 2026-08-17,
 * and this test is what stops it being removed again on the same privacy argument.
 *
 * Linking is an ACT, not a read: `previous_employee_id` fixes prior service across employers,
 * which a Leave spec will compute entitlement from (BR-13). An HR who links an AIM record without
 * being shown "AIM" is performing a cross-company act blind, and hiding the employer hides what
 * they are doing rather than protecting anything. The six companies are not secret either —
 * `CLAUDE.md` §5 lists them and the employee list renders them in its own filter.
 */
it('names the prior employer even when it is a company the actor could not otherwise read', function () {
    $prior = endedEmployment($this->aim, ['ic_no' => '330303103333']);

    expect($this->lookup->find('330303103333')->companyName)
        ->toBe('AL HADDAD INTEGRATED MARKETING')
        ->and($prior->company_id)->toBe($this->aim->id);
});

/**
 * ⚠ BOTH DATES ARE NULLABLE AND NEITHER IS GUARANTEED. `join_date` is nullable, and a terminal
 * record can carry no ledger row at all — `AccountExpiry` documents and tests that state. A caller
 * that renders the period without asking renders "– to –".
 */
it('answers with no service period when the dates are not there to read', function () {
    $prior = Employee::factory()->forCompany($this->aim)->resigned()
        ->create(['ic_no' => '220202102222', 'join_date' => null]);

    $answer = $this->lookup->find('220202102222');

    expect($answer->employeeId)->toBe($prior->id)
        ->and($answer->servedFrom)->toBeNull()
        ->and($answer->servedTo)->toBeNull()
        ->and($answer->hasServicePeriod())->toBeFalse();
});
