<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\User;
use App\Policies\EmployeePolicy;

/**
 * The Personal tab's field tiering — `adr/0014` decisions 1 and 2, spec §6.2 and §7.1.
 *
 * ⚠ EVERY SUPERVISORY FIXTURE HERE NAMES THE ACTOR IN `direct_supervisor_id`, AND THAT IS NOT
 * INCIDENTAL. `personalFieldsFor()` sits behind the same reporting-line bound `viewTab()`
 * applies, so a supervisor who does not supervise the subject is refused by the OUTER guard
 * and never reaches the field rule at all. Such a test would go red on a broken field list —
 * red for the wrong reason, which `conventions.md` §9 records as being as undetectable as a
 * break that produces green, and rather more convincing.
 *
 * ⚠ EVERY NEGATIVE IS PAIRED WITH A POSITIVE ON AN ADMINISTRATIVE ACTOR. "This field is
 * absent" passes just as well against an empty list as against a correct one, and an empty
 * list is exactly what a broken constant would produce. The pair is what distinguishes the
 * two states.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->tursenia = Company::factory()->subsidiary($this->ahs)->create(['code' => 'TURSENIA']);

    $this->shared = Department::factory()->shared()->create(['name' => 'Logistics']);

    $this->policy = app(EmployeePolicy::class);
});

function tieringStaffAt(Company $company): Employee
{
    return Employee::factory()->forCompany($company)->create(['department_id' => test()->shared->id]);
}

/** ⚠ The subject NAMES the actor. Without this the outer bound refuses before the field rule runs. */
function tieringStaffReportingTo(Employee $supervisor, Company $company): Employee
{
    return Employee::factory()
        ->forCompany($company)
        ->reportingTo($supervisor)
        ->create(['department_id' => test()->shared->id]);
}

function tieringAccountHolding(string $role, Company $roleAt, Employee $employee): User
{
    EmployeeRole::factory()->forCompany($roleAt)->role($role)->create(['employee_id' => $employee->id]);

    return User::factory()->forEmployee($employee)->create();
}

it('gives a supervisor four fields on a subordinate, and no more', function () {
    $supervisorEmployee = tieringStaffAt($this->aim);
    $supervisor = tieringAccountHolding('SUPERVISOR', $this->aim, $supervisorEmployee);
    $subject = tieringStaffReportingTo($supervisorEmployee, $this->aim);

    expect($this->policy->personalFieldsFor($supervisor, $subject))
        ->toBe(['full_name', 'nickname', 'email', 'phone_no']);
});

it('gives a manager the same four through manager_id', function () {
    $managerEmployee = tieringStaffAt($this->aim);
    $manager = tieringAccountHolding('MANAGER', $this->aim, $managerEmployee);
    $subject = Employee::factory()->forCompany($this->aim)->managedBy($managerEmployee)
        ->create(['department_id' => $this->shared->id]);

    expect($this->policy->personalFieldsFor($manager, $subject))
        ->toBe(EmployeePolicy::PERSONAL_FIELDS_SUPERVISORY);
});

/**
 * ⚠ THE PAIRED POSITIVE for every withholding assertion in this file. HR reads the same
 * record and gets all sixteen, so an empty or truncated constant cannot pass the tests above
 * by accident.
 */
it('gives the administrative tier every field on the same record', function () {
    $hr = tieringAccountHolding('HR', $this->ahs, tieringStaffAt($this->ahs));
    $subject = tieringStaffAt($this->aim);

    expect($this->policy->personalFieldsFor($hr, $subject))
        ->toBe(EmployeePolicy::PERSONAL_FIELDS_ALL)
        ->toContain('ic_no', 'bank_account_no', 'address', 'date_of_birth');
});

/**
 * `ACCOUNT` reads every tab within scope (`adr/0004` decision 8), and §6.4's "grants nothing
 * in this module" is about the ACTION matrix. Payroll cannot run blind.
 */
it('gives ACCOUNT every field, because reading and writing are different questions', function () {
    $account = tieringAccountHolding('ACCOUNT', $this->ahs, tieringStaffAt($this->ahs));

    expect($this->policy->personalFieldsFor($account, tieringStaffAt($this->tursenia)))
        ->toBe(EmployeePolicy::PERSONAL_FIELDS_ALL);
});

it('gives FULL and VIEW_ONLY every field, holding no employee record at all', function () {
    $subject = tieringStaffAt($this->aim);

    expect($this->policy->personalFieldsFor(User::factory()->masterAdmin()->create(), $subject))
        ->toBe(EmployeePolicy::PERSONAL_FIELDS_ALL)
        ->and($this->policy->personalFieldsFor(User::factory()->viewOnly()->create(), $subject))
        ->toBe(EmployeePolicy::PERSONAL_FIELDS_ALL);
});

/**
 * ⚠ The own-record branch runs BEFORE any role check, so it must not depend on holding one.
 * This actor holds no `employee_roles` row at all — the staff state (adr/0003 decision 1).
 */
it('gives an employee every field on their own record, holding no role whatsoever', function () {
    $employee = tieringStaffAt($this->aim);
    $account = User::factory()->forEmployee($employee)->create();

    expect($this->policy->personalFieldsFor($account, $employee))
        ->toBe(EmployeePolicy::PERSONAL_FIELDS_ALL);
});

/**
 * ⚠ Holding both tiers is not holding the lesser one. The actor is employed by AHS, so their
 * read scope is the whole group and their AIM `HR` row sits inside it; the administrative
 * branch resolves first, exactly as it does in viewTab().
 */
it('resolves administrative first for an actor who is also a supervisor', function () {
    $actorEmployee = tieringStaffAt($this->ahs);
    EmployeeRole::factory()->forCompany($this->ahs)->role('SUPERVISOR')->create(['employee_id' => $actorEmployee->id]);
    EmployeeRole::factory()->forCompany($this->aim)->role('HR')->create(['employee_id' => $actorEmployee->id]);
    $actor = User::factory()->forEmployee($actorEmployee)->create();

    $subordinate = tieringStaffReportingTo($actorEmployee, $this->ahs);

    expect($this->policy->personalFieldsFor($actor, $subordinate))
        ->toBe(EmployeePolicy::PERSONAL_FIELDS_ALL);
});

it('refuses outright outside the read scope, before any tier is considered', function () {
    $hr = tieringAccountHolding('HR', $this->aim, tieringStaffAt($this->aim));

    expect($this->policy->personalFieldsFor($hr, tieringStaffAt($this->tursenia)))->toBe([]);
});

/**
 * ⚠ The bound `personalFieldsFor()` shares with viewTab(): a supervisor is a supervisor OF
 * SOMEBODY, not of a company. This subject leaves both BR-8 columns null — `adr/0011`
 * decision 4 — and is read by nobody below `HR`.
 */
it('gives a supervisor nothing on an employee who does not report to them', function () {
    $supervisorEmployee = tieringStaffAt($this->aim);
    $supervisor = tieringAccountHolding('SUPERVISOR', $this->aim, $supervisorEmployee);

    expect($this->policy->personalFieldsFor($supervisor, tieringStaffAt($this->aim)))->toBe([]);
});

/**
 * ⚠ `nationality` and `gender` are the test of the rule, not an afterthought. Neither looks
 * dangerous, and admitting a field because it seems harmless replaces the written argument
 * with an estimate (`adr/0014` decision 1).
 *
 * The constants are asserted non-empty first: a guard over a collection that never checks the
 * collection has anything in it is the shape `conventions.md` §9 records three times.
 */
it('keeps nationality and gender on the administrative side, and the four a subset of all', function () {
    expect(EmployeePolicy::PERSONAL_FIELDS_SUPERVISORY)->toHaveCount(4)
        ->and(EmployeePolicy::PERSONAL_FIELDS_ALL)->toHaveCount(16);

    foreach (['nationality', 'gender'] as $field) {
        expect(EmployeePolicy::PERSONAL_FIELDS_SUPERVISORY)->not->toContain($field)
            ->and(EmployeePolicy::PERSONAL_FIELDS_ALL)->toContain($field);
    }

    expect(array_diff(EmployeePolicy::PERSONAL_FIELDS_SUPERVISORY, EmployeePolicy::PERSONAL_FIELDS_ALL))
        ->toBe([]);
});

/**
 * Decision 2's derivation, asserted in BOTH directions across a population — the guard that
 * the boolean and the list cannot part company.
 *
 * ⚠ It compares outcomes rather than implementations, the same shape as
 * `EmployeeListVisibilityTest`. An implementation that answered the tab question separately
 * would pass every other test in this file and fail only here.
 */
it('answers viewTab(TAB_PERSONAL) exactly when the field list is not empty', function () {
    $supervisorEmployee = tieringStaffAt($this->aim);
    $supervisor = tieringAccountHolding('SUPERVISOR', $this->aim, $supervisorEmployee);
    $hrEmployee = tieringStaffAt($this->ahs);
    $hr = tieringAccountHolding('HR', $this->ahs, $hrEmployee);
    $subsidiaryHr = tieringAccountHolding('HR', $this->aim, tieringStaffAt($this->aim));

    $actors = [$supervisor, $hr, $subsidiaryHr, User::factory()->masterAdmin()->create()];
    $subjects = [
        tieringStaffReportingTo($supervisorEmployee, $this->aim),
        tieringStaffAt($this->aim),
        tieringStaffAt($this->tursenia),
        $hrEmployee,
    ];

    $compared = 0;

    foreach ($actors as $actor) {
        foreach ($subjects as $subject) {
            expect($this->policy->viewTab($actor, $subject, EmployeePolicy::TAB_PERSONAL))
                ->toBe($this->policy->personalFieldsFor($actor, $subject) !== []);

            $compared++;
        }
    }

    // The population is asserted non-empty for the same reason the constants are above.
    expect($compared)->toBe(16);
});

// ─── adr/0014 extended to writing — writableFieldsFor() ──────────────────────────────────────

/**
 * writable ⊆ readable, over every actor shape rather than spot checked.
 *
 * ⚠ THIS GUARD CANNOT FAIL TODAY, AND SAYING SO IS THE POINT — measured 2026-08-17 by replacing
 * the derivation in `writableFieldsFor()` with a second literal list and watching this test stay
 * GREEN. The reason is `update()`: every actor whose readable set is NARROWER than the full tab —
 * the supervisory tier, VIEW_ONLY, the employee on their own record, an account with no role — is
 * refused by it and gets `[]` before the field logic runs at all. The only actors that reach the
 * field logic are `HR` and `ASSISTANT_DIRECTOR`, whose readable set IS the full tab, so the
 * difference is empty whatever the method returns.
 *
 * ⚠ SO WHAT CAUGHT THAT BREAK WAS THE `phone_no` TEST BELOW, NOT THIS ONE. Do not read a green
 * run here as evidence the derivation is intact.
 *
 * It is kept because the property it states is the one that must hold, and it becomes live the
 * moment a role reading a narrower set is added to `WRITE_ROLES` — which is precisely the change
 * `writableFieldsFor()` exists to survive. It is a guard for a future edit, not a guard that has
 * been exercised, and `conventions.md` §9 is why the difference is written down instead of left
 * to look the same.
 */
it('never allows a field to be written that the same actor cannot read', function () {
    $supervisorEmployee = tieringStaffAt($this->aim);
    $supervisorAccount = tieringAccountHolding('SUPERVISOR', $this->aim, $supervisorEmployee);
    $supervised = tieringStaffReportingTo($supervisorEmployee, $this->aim);

    $actors = [
        'HR' => tieringAccountHolding('HR', $this->ahs, tieringStaffAt($this->ahs)),
        'ASSISTANT_DIRECTOR' => tieringAccountHolding('ASSISTANT_DIRECTOR', $this->ahs, tieringStaffAt($this->ahs)),
        'ACCOUNT' => tieringAccountHolding('ACCOUNT', $this->aim, tieringStaffAt($this->aim)),
        'SUPERVISOR' => $supervisorAccount,
        'MASTER_ADMIN' => User::factory()->masterAdmin()->create(),
        'VIEW_ONLY' => User::factory()->create(['system_access' => 'VIEW_ONLY', 'employee_id' => null]),
        'OWN_RECORD' => User::factory()->forEmployee($supervised)->create(),
        'NO_ROLE' => User::factory()->forEmployee(tieringStaffAt($this->tursenia))->create(),
    ];

    $violations = [];

    foreach ($actors as $label => $actor) {
        $readable = $this->policy->personalFieldsFor($actor, $supervised);
        $writable = $this->policy->writableFieldsFor($actor, $supervised);

        foreach (array_diff($writable, $readable) as $field) {
            $violations[] = "{$label} may write {$field} without reading it";
        }
    }

    expect($violations)->toBe([]);
});

/**
 * ⚠ THE TIER `adr/0014` WITHHOLDS TWELVE FIELDS FROM WRITES NOTHING AT ALL, and that is §6's
 * matrix rather than a new rule: `WRITE_ROLES` never contained the supervisory roles. The pair is
 * the point — four readable, zero writable — because "zero writable" passes just as well against
 * a method that always returns [].
 */
it('gives a supervisor four readable fields and no writable ones', function () {
    // ⚠ THE SUPERVISOR NEEDS THE ROLE ROW, NOT ONLY THE REPORTING LINE. Without the
    // employee_roles row the outer bound refuses and personalFieldsFor() returns [] — which
    // makes "no writable fields" pass for the wrong reason, exactly what this file's own header
    // warns about. tieringAccountHolding() grants it.
    $supervisorEmployee = tieringStaffAt($this->aim);
    $supervisor = tieringAccountHolding('SUPERVISOR', $this->aim, $supervisorEmployee);
    $supervised = tieringStaffReportingTo($supervisorEmployee, $this->aim);

    expect($this->policy->personalFieldsFor($supervisor, $supervised))
        ->toBe(EmployeePolicy::PERSONAL_FIELDS_SUPERVISORY)
        ->and($this->policy->writableFieldsFor($supervisor, $supervised))
        ->toBe([]);
});

/**
 * ⚠ `phone_no` IS READABLE BY BOTH TIERS AND WRITABLE BY NOBODY, which is the only reason
 * NEVER_WRITABLE_ON_THIS_FORM has to exist — the readable set cannot express it. It is the login
 * username on `users`, a credential changed from account management alone (§6.4, `adr/0006`
 * decision 7, `adr/0004` decision 6).
 */
it('withholds the login username from writing while leaving it readable', function () {
    $subject = tieringStaffAt($this->aim);
    $hr = tieringAccountHolding('HR', $this->ahs, tieringStaffAt($this->ahs));

    expect($this->policy->personalFieldsFor($hr, $subject))->toContain('phone_no')
        ->and($this->policy->writableFieldsFor($hr, $subject))->not->toContain('phone_no')
        // The rest of the administrative set is writable — otherwise "phone_no absent" would
        // pass against an empty list.
        ->and($this->policy->writableFieldsFor($hr, $subject))->toContain('ic_no', 'bank_account_no');
});

/**
 * ⚠ THE TWO ACTORS WHO READ EVERYTHING AND WRITE NOTHING, and they are the reason
 * `writableFieldsFor()` calls `update()` first rather than filtering the readable set alone.
 *
 * `VIEW_ONLY` reads the whole group and writes nothing — the one place its read scope and its
 * abilities part company (`adr/0004` decision 2). The employee reads their own record in full,
 * and correcting it is **Employee Self-Service, a module of its own that does not exist**
 * (`CLAUDE.md` §10) — not this form.
 */
it('gives VIEW_ONLY and the employee themselves every field to read and none to write', function () {
    $subject = tieringStaffAt($this->aim);

    $viewOnly = User::factory()->create(['system_access' => 'VIEW_ONLY', 'employee_id' => null]);
    $themselves = User::factory()->forEmployee($subject)->create();

    foreach ([$viewOnly, $themselves] as $actor) {
        expect($this->policy->personalFieldsFor($actor, $subject))
            ->toBe(EmployeePolicy::PERSONAL_FIELDS_ALL)
            ->and($this->policy->writableFieldsFor($actor, $subject))
            ->toBe([]);
    }
});

/**
 * ⚠ `ACCOUNT` READS EVERY FIELD AND WRITES NONE. It is administrative for reading (`adr/0014`
 * decision 1) and absent from `WRITE_ROLES` — reading and writing are different questions, and
 * this is the case where they give different answers for the same tier.
 */
it('gives ACCOUNT every field to read and none to write', function () {
    $subject = tieringStaffAt($this->aim);
    $account = tieringAccountHolding('ACCOUNT', $this->aim, tieringStaffAt($this->aim));

    expect($this->policy->personalFieldsFor($account, $subject))
        ->toBe(EmployeePolicy::PERSONAL_FIELDS_ALL)
        ->and($this->policy->writableFieldsFor($account, $subject))
        ->toBe([]);
});
