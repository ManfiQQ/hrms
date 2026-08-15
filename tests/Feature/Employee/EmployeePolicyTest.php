<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\User;
use App\Policies\EmployeePolicy;

/**
 * The §6 action matrix and the §6.2 tab matrix (`adr/0004` decisions 8 and 9).
 *
 * ⚠ THE TWO AXES MUST DISAGREE, AND THAT IS WHAT THESE TESTS ARE FOR. A subsidiary-employed
 * `HR` approves across the whole group while reading ONE COMPANY only. If an implementation
 * ever makes approval scope and read scope agree by construction, it has merged them — and
 * the merge is a silent widening of access, not a simplification (conventions.md §2).
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->tursenia = Company::factory()->subsidiary($this->ahs)->create(['code' => 'TURSENIA']);

    // Shared: belongs to no single company, and holds staff from several (adr/0002).
    $this->shared = Department::factory()->shared()->create(['name' => 'Logistics']);

    $this->policy = app(EmployeePolicy::class);
});

/**
 * Staff who report to NOBODY — both BR-8 columns null, as `EmployeeFactory` leaves them.
 *
 * ⚠ Since `adr/0011` this is no longer a neutral default. An employee with both columns null
 * is read by nobody below `HR` (decision 4), so every subject built by this helper is
 * INVISIBLE to the supervisory tier by construction. That is deliberate: it forces each
 * supervisory test to say out loud which reporting line it is arranging.
 */
function policyStaffAt(Company $company, ?Department $department = null): Employee
{
    return Employee::factory()
        ->forCompany($company)
        ->create(['department_id' => ($department ?? test()->shared)->id]);
}

/** Staff whose `direct_supervisor_id` names $supervisor (BR-8, `adr/0011` decision 1). */
function policyStaffReportingTo(Employee $supervisor, Company $company, ?Department $department = null): Employee
{
    return Employee::factory()
        ->forCompany($company)
        ->reportingTo($supervisor)
        ->create(['department_id' => ($department ?? test()->shared)->id]);
}

/** Staff whose `manager_id` names $manager, with `direct_supervisor_id` left null. */
function policyStaffManagedBy(Employee $manager, Company $company, ?Department $department = null): Employee
{
    return Employee::factory()
        ->forCompany($company)
        ->managedBy($manager)
        ->create(['department_id' => ($department ?? test()->shared)->id]);
}

function policyAccountHolding(string $role, Company $roleAt, Employee $employee): User
{
    EmployeeRole::factory()->forCompany($roleAt)->role($role)->create(['employee_id' => $employee->id]);

    return User::factory()->forEmployee($employee)->create();
}

it('lets an AHS-employed HR read every tab of any group company employee', function () {
    $hr = policyAccountHolding('HR', $this->ahs, policyStaffAt($this->ahs));
    $subject = policyStaffAt($this->tursenia);

    foreach ([EmployeePolicy::TAB_EMPLOYMENT, EmployeePolicy::TAB_FAMILY, EmployeePolicy::TAB_DOCUMENTS] as $tab) {
        expect($this->policy->viewTab($hr, $subject, $tab))->toBeTrue();
    }
});

/**
 * ⚠ THE TEST THAT MUST USE A SUBSIDIARY-EMPLOYED HR, per §8's warning. An AHS-employed HR
 * reads the whole group legitimately, so writing this with one asserts nothing — it would
 * pass for the wrong reason and hide a merged-axes bug.
 */
it('stops a subsidiary-employed HR at its own company, however wide its approval authority', function () {
    $hr = policyAccountHolding('HR', $this->aim, policyStaffAt($this->aim));

    expect($this->policy->view($hr, policyStaffAt($this->aim)))->toBeTrue()
        ->and($this->policy->view($hr, policyStaffAt($this->tursenia)))->toBeFalse();
});

/**
 * ⚠ §6.2's central rule. A supervisor needs to know who reports to them and how to reach
 * them; they do not need a copy of someone's IC or their spouse's identity card number.
 *
 * The subject reports to the supervisor since `adr/0011`: the tabs a supervisor may open are
 * decided here, but WHICH employees they may open at all is the reporting line.
 */
it('gives a supervisor Employment and Personal, and nothing else', function () {
    $supervisorEmployee = policyStaffAt($this->aim);
    $subject = policyStaffReportingTo($supervisorEmployee, $this->aim);
    $supervisor = policyAccountHolding('SUPERVISOR', $this->aim, $supervisorEmployee);

    expect($this->policy->viewTab($supervisor, $subject, EmployeePolicy::TAB_EMPLOYMENT))->toBeTrue()
        ->and($this->policy->viewTab($supervisor, $subject, EmployeePolicy::TAB_PERSONAL))->toBeTrue();

    foreach ([
        EmployeePolicy::TAB_FAMILY,
        EmployeePolicy::TAB_EDUCATION,
        EmployeePolicy::TAB_EMPLOYMENT_HISTORY,
        EmployeePolicy::TAB_DOCUMENTS,
        EmployeePolicy::TAB_STATUS_HISTORY,
    ] as $tab) {
        expect($this->policy->viewTab($supervisor, $subject, $tab))->toBeFalse();
    }
});

/**
 * ⚠ BR-10, AND THE SHARED DEPARTMENT IS EXACTLY WHERE THIS IS MOST LIKELY TO BE GOT WRONG,
 * because the two employees are visibly colleagues sitting in one department.
 *
 * An HOD's authority is strictly same-company. The comparison reads `employees.company_id` on
 * both sides — the payroll employer — never `employee_roles.company_id` for the subject.
 *
 * ⚠ SINCE `adr/0011` BOTH SUBJECTS REPORT TO THE HOD, and that is what makes this test worth
 * having. The reporting line is satisfied on both sides, the department is shared on both
 * sides, and the ONLY difference left is the employer — so a pass here can mean nothing except
 * that the company bound held. Under the old department rule the two subjects differed in
 * nothing at all, and the test could not distinguish the bound it names from the one it does
 * not.
 */
it('refuses an HOD of a shared department another company\'s employee in that same department', function () {
    $hodEmployee = policyStaffAt($this->aim, $this->shared);
    $hod = policyAccountHolding('HOD', $this->aim, $hodEmployee);

    $ownCompany = policyStaffReportingTo($hodEmployee, $this->aim, $this->shared);
    $otherCompany = policyStaffReportingTo($hodEmployee, $this->tursenia, $this->shared);

    expect($this->policy->view($hod, $ownCompany))->toBeTrue()
        ->and($this->policy->view($hod, $otherCompany))->toBeFalse();
});

/**
 * ⚠ THE NARROWING, and this test replaces one that could no longer fail.
 *
 * It previously read *"refuses a supervisor an employee in a different department of their own
 * company"* and asserted `false` alone. After `adr/0011` that subject is refused for having no
 * reporting line, not for its department — the assertion stayed green while testing nothing,
 * which is the empty-guard family `conventions.md` §9 lists.
 *
 * The rule that replaced it is refused in the direction the old one got WRONG: same company,
 * same department, visibly a colleague — and not a subordinate. Department equality would
 * return true here (`adr/0011` Context).
 */
it('refuses a supervisor a colleague in their own department who does not report to them', function () {
    $supervisorEmployee = policyStaffAt($this->aim, $this->shared);
    $supervisor = policyAccountHolding('SUPERVISOR', $this->aim, $supervisorEmployee);

    $colleague = policyStaffAt($this->aim, $this->shared);
    $subordinate = policyStaffReportingTo($supervisorEmployee, $this->aim, $this->shared);

    // Both halves, or the test passes against a supervisor who can read nothing at all.
    expect($this->policy->view($supervisor, $colleague))->toBeFalse()
        ->and($this->policy->view($supervisor, $subordinate))->toBeTrue();
});

/**
 * ⚠ THE WIDENING — the half department equality got wrong in the other direction, and the
 * reason `adr/0011` is a correction rather than a tightening.
 *
 * A supervisor's own subordinate, sitting in a different department. The old rule refused
 * this: someone who reports to you, whose leave you approve, whose phone number you may need
 * at an accident, and whom the system hid from you because a `department_id` differed.
 */
it('gives a supervisor a subordinate who sits in a different department', function () {
    $studio = Department::factory()->shared()->create(['name' => 'Studio']);

    $supervisorEmployee = policyStaffAt($this->aim, $this->shared);
    $supervisor = policyAccountHolding('SUPERVISOR', $this->aim, $supervisorEmployee);

    $subordinate = policyStaffReportingTo($supervisorEmployee, $this->aim, $studio);

    expect($this->policy->view($supervisor, $subordinate))->toBeTrue()
        ->and($this->policy->viewTab($supervisor, $subordinate, EmployeePolicy::TAB_PERSONAL))->toBeTrue();
});

/**
 * ⚠ `manager_id` ALONE IS ENOUGH — `adr/0011` decision 1 accepts either column, and decision 2
 * calls two levels without traversal the ordinary case.
 *
 * `direct_supervisor_id` is left null deliberately. An implementation reading only the first
 * column passes every other test in this file and fails this one.
 */
it('gives a manager an employee who names them in manager_id alone', function () {
    $workshop = Department::factory()->shared()->create(['name' => 'Workshop']);

    $managerEmployee = policyStaffAt($this->aim, $this->shared);
    $manager = policyAccountHolding('MANAGER', $this->aim, $managerEmployee);

    // A different department, so `manager_id` is the only thing that can grant this read.
    $subject = policyStaffManagedBy($managerEmployee, $this->aim, $workshop);

    expect($subject->direct_supervisor_id)->toBeNull()
        ->and($this->policy->view($manager, $subject))->toBeTrue();
});

/**
 * ⚠ `adr/0011` DECISION 4, AND IT IS ACCEPTED RATHER THAN WORKED AROUND. An employee with both
 * BR-8 columns null is read by nobody at the supervisory tier.
 *
 * Both directions, or this asserts only that something is broken: `HR` must still read the
 * same record. The rule is *"nobody BELOW HR"*, not *"nobody"* — and an unfilled column
 * showing up as an employee missing from every supervisor's view is the visible data gap
 * decision 4 chose over an invented chain.
 */
it('hides an employee with no supervisor and no manager from the supervisory tier, but not from HR', function () {
    $orphan = policyStaffAt($this->aim, $this->shared);

    expect($orphan->direct_supervisor_id)->toBeNull()
        ->and($orphan->manager_id)->toBeNull();

    foreach (['SUPERVISOR', 'MANAGER', 'HOD'] as $role) {
        $actor = policyAccountHolding($role, $this->aim, policyStaffAt($this->aim, $this->shared));

        expect($this->policy->view($actor, $orphan))
            ->toBeFalse("{$role} must not read an employee who reports to nobody");
    }

    $hr = policyAccountHolding('HR', $this->ahs, policyStaffAt($this->ahs));

    expect($this->policy->view($hr, $orphan))->toBeTrue();
});

it('gives an employee every tab of their own record', function () {
    $employee = policyStaffAt($this->aim);
    $account = User::factory()->forEmployee($employee)->create();

    expect($this->policy->viewTab($account, $employee, EmployeePolicy::TAB_FAMILY))->toBeTrue()
        ->and($this->policy->viewTab($account, $employee, EmployeePolicy::TAB_DOCUMENTS))->toBeTrue();
});

/**
 * §6.3 — the employee retrieves seven of the eight types. OTHER is withheld, which is what
 * gives it a defined purpose as the home for internal notes and investigation material
 * rather than an undifferentiated bucket.
 *
 * ⚠ `PHOTO` joined the enum on 2026-08-14 (`adr/0013` decision 7) and is asserted here in BOTH
 * directions on purpose. A test showing only that the employee may open their photo would pass
 * against a policy that had stopped withholding anything at all — and the withheld type is the
 * half that carries the rule.
 */
it('lets an employee open their own documents except OTHER', function () {
    $employee = policyStaffAt($this->aim);
    $account = User::factory()->forEmployee($employee)->create();

    expect($this->policy->viewDocument($account, $employee, 'IC'))->toBeTrue()
        ->and($this->policy->viewDocument($account, $employee, 'RESIGNATION_LETTER'))->toBeTrue()
        ->and($this->policy->viewDocument($account, $employee, 'PHOTO'))->toBeTrue()
        ->and($this->policy->viewDocument($account, $employee, 'OTHER'))->toBeFalse();
});

it('holds ordinary staff to their own record only', function () {
    $employee = policyStaffAt($this->aim);
    $account = User::factory()->forEmployee($employee)->create();

    expect($this->policy->view($account, $employee))->toBeTrue()
        ->and($this->policy->view($account, policyStaffAt($this->aim)))->toBeFalse();
});

it('lets HR create and edit within scope but never grant a restricted role', function () {
    $hr = policyAccountHolding('HR', $this->aim, policyStaffAt($this->aim));
    $subject = policyStaffAt($this->aim);

    expect($this->policy->create($hr, $this->aim->id))->toBeTrue()
        ->and($this->policy->update($hr, $subject))->toBeTrue()
        ->and($this->policy->grantRole($hr, $subject, 'MANAGER', $this->aim->id))->toBeTrue();

    foreach (EmployeeRole::RESTRICTED as $role) {
        expect($this->policy->grantRole($hr, $subject, $role, $this->aim->id))->toBeFalse();
    }
});

it('reserves the job function vocabulary and employee_no to Master Admin', function () {
    $hr = policyAccountHolding('HR', $this->ahs, policyStaffAt($this->ahs));
    $master = User::factory()->create(['system_access' => 'FULL', 'employee_id' => null]);

    expect($this->policy->manageJobFunctionTypes($hr))->toBeFalse()
        ->and($this->policy->manageJobFunctionTypes($master))->toBeTrue()
        ->and($this->policy->editEmployeeNo($hr, policyStaffAt($this->ahs)))->toBeFalse()
        ->and($this->policy->editEmployeeNo($master, policyStaffAt($this->ahs)))->toBeTrue();
});

/**
 * ⚠ VIEW_ONLY is the one account type whose read scope and abilities part company: group-wide
 * reads, writes nothing, approves nothing (`adr/0004` decision 2).
 */
it('lets VIEW_ONLY read the whole group and write none of it', function () {
    $viewer = User::factory()->create(['system_access' => 'VIEW_ONLY', 'employee_id' => null]);
    $subject = policyStaffAt($this->tursenia);

    expect($this->policy->view($viewer, $subject))->toBeTrue()
        ->and($this->policy->viewTab($viewer, $subject, EmployeePolicy::TAB_DOCUMENTS))->toBeTrue()
        ->and($this->policy->update($viewer, $subject))->toBeFalse()
        ->and($this->policy->archive($viewer, $subject))->toBeFalse();
});

/**
 * ⚠ Revoked authority is not current authority, asserted through the policy rather than by
 * hand-writing the condition. A query missing `revoked_date IS NULL` returns revoked
 * authority as live and NOTHING FAILS — the record is simply read by someone who should no
 * longer be able to.
 */
it('stops reading through a role once it is revoked', function () {
    $subject = policyStaffAt($this->tursenia);

    // ⚠ Employed by AHS from the start, not moved here afterwards: Employee::$fillable
    // deliberately omits company_id, because a transfer cascades four child tables and is
    // audited (§5.7, BR-17). An ->update() would silently do nothing.
    $employee = policyStaffAt($this->ahs);

    $role = EmployeeRole::factory()->forCompany($this->ahs)->role('HR')
        ->create(['employee_id' => $employee->id]);

    $hr = User::factory()->forEmployee($employee)->create();

    expect($this->policy->view($hr, $subject))->toBeTrue();

    // ⚠ AuthorshipContext: revoking here ARRANGES the state the read is tested against. The
    // act under test is the policy read that follows, not the revocation (conventions.md §9).
    app(App\Services\Audit\AuthorshipContext::class)->run(
        $hr,
        'Fixture: revoking the role so the read can be observed afterwards.',
        fn () => $role->update(['revoked_date' => now()->toDateString()])
    );

    expect($this->policy->view($hr->fresh(), $subject))->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════════════
// TRANSFER — §6, §5.7. Added 2026-08-13; nothing authorised a transfer before it.
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * ⚠ THIS ABILITY DID NOT EXIST UNTIL 2026-08-13, and TransferCompany already carried a
 * comment claiming it did — "EmployeePolicy requires the employee's company to be inside the
 * actor's read scope". Nothing authorised a transfer at all.
 *
 * No test caught it because the Action had never been reached through an authorised path:
 * §7's UI does not exist, so its only caller was a test invoking it directly. The negative
 * cases below are the half that matters — a permission that says yes to the right person and
 * nothing else is the only kind worth having.
 */
it('lets HR transfer within its read scope', function () {
    $hr = policyAccountHolding('HR', $this->ahs, policyStaffAt($this->ahs));

    expect($this->policy->transfer($hr, policyStaffAt($this->aim), $this->tursenia))->toBeTrue();
});

it('lets Master Admin transfer as the support path', function () {
    $master = User::factory()->masterAdmin()->create();

    expect($this->policy->transfer($master, policyStaffAt($this->aim), $this->tursenia))->toBeTrue();
});

/**
 * ⚠ Every role that is NOT HR, tested as a group. §6 names two actors for a transfer and
 * ASSISTANT_DIRECTOR is not one of them, despite holding create / edit / archive — a transfer
 * is not a profile edit, it reassigns statutory responsibility between two legal entities.
 */
it('refuses every role except HR', function () {
    $subject = policyStaffAt($this->aim);

    foreach (['ASSISTANT_DIRECTOR', 'ACCOUNT', 'SUPERVISOR', 'MANAGER', 'HOD'] as $role) {
        $actor = policyAccountHolding($role, $this->ahs, policyStaffAt($this->ahs));

        expect($this->policy->transfer($actor, $subject, $this->tursenia))
            ->toBeFalse("{$role} must not be able to transfer an employee");
    }
});

it('refuses ordinary staff, including for their own record', function () {
    $employee = policyStaffAt($this->aim);
    $account = User::factory()->forEmployee($employee)->create();

    expect($this->policy->transfer($account, $employee, $this->tursenia))->toBeFalse()
        ->and($this->policy->transfer($account, policyStaffAt($this->aim), $this->tursenia))->toBeFalse();
});

it('refuses VIEW_ONLY, which reads the group and writes none of it', function () {
    $viewer = User::factory()->viewOnly()->create();

    expect($this->policy->transfer($viewer, policyStaffAt($this->aim), $this->tursenia))->toBeFalse();
});

/**
 * ⚠ The check TransferCompany's cascade comment depends on. Remove it and that comment turns
 * false again, and the cascade's tenant-scope lift becomes the only thing standing.
 */
it('refuses an employee outside the actor read scope', function () {
    $subsidiaryHr = policyAccountHolding('HR', $this->aim, policyStaffAt($this->aim));

    expect($this->policy->transfer($subsidiaryHr, policyStaffAt($this->tursenia), $this->aim))->toBeFalse();
});

/** The destination is checked for the same reason grantRole() checks the company granted in. */
it('refuses a destination outside the actor read scope', function () {
    $subsidiaryHr = policyAccountHolding('HR', $this->aim, policyStaffAt($this->aim));

    expect($this->policy->transfer($subsidiaryHr, policyStaffAt($this->aim), $this->tursenia))->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════════════
// TAB_ROLES — the eighth tab, decided 2026-08-13
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * ⚠ THIS ROW HAD NEVER BEEN DECIDED. adr/0004 decision 8's table named seven tabs while §7
 * listed eight, and viewTab() answered `false` for the missing one by letting an unrecognised
 * string fall through the supervisory branch — an access rule nobody chose.
 */
it('gives the administrative tier the Roles & Functions tab', function () {
    $subject = policyStaffAt($this->aim);

    foreach (['HR', 'ASSISTANT_DIRECTOR', 'ACCOUNT'] as $role) {
        $actor = policyAccountHolding($role, $this->ahs, policyStaffAt($this->ahs));

        expect($this->policy->viewTab($actor, $subject, EmployeePolicy::TAB_ROLES))
            ->toBeTrue("{$role} must be able to read Roles & Functions");
    }

    expect($this->policy->viewTab(User::factory()->masterAdmin()->create(), $subject, EmployeePolicy::TAB_ROLES))->toBeTrue()
        ->and($this->policy->viewTab(User::factory()->viewOnly()->create(), $subject, EmployeePolicy::TAB_ROLES))->toBeTrue();
});

/**
 * ⚠ The negative direction, and it is the decision. Supervisors keep the Employment tab's
 * BR-12 cross-company line — who holds what authority TODAY — and lose the history, the job
 * functions and the grant controls this tab adds.
 */
it('withholds Roles & Functions from the supervisory tier', function () {
    foreach (['SUPERVISOR', 'MANAGER', 'HOD'] as $role) {
        $actorEmployee = policyStaffAt($this->aim, $this->shared);
        $actor = policyAccountHolding($role, $this->aim, $actorEmployee);

        // Reports to this actor, so the Employment assertion below is about the TAB. Since
        // adr/0011 a subject that reports to nobody is refused every tab, which would make
        // the negative half pass for the wrong reason.
        $subject = policyStaffReportingTo($actorEmployee, $this->aim, $this->shared);

        // The same actor DOES read Employment — so this is the tab being withheld, not the
        // record. Asserting only the negative would pass against an actor who sees nothing.
        expect($this->policy->viewTab($actor, $subject, EmployeePolicy::TAB_EMPLOYMENT))
            ->toBeTrue("{$role} must still read Employment")
            ->and($this->policy->viewTab($actor, $subject, EmployeePolicy::TAB_ROLES))
            ->toBeFalse("{$role} must not read Roles & Functions");
    }
});

it('gives an employee their own Roles & Functions tab', function () {
    $employee = policyStaffAt($this->aim);
    $account = User::factory()->forEmployee($employee)->create();

    expect($this->policy->viewTab($account, $employee, EmployeePolicy::TAB_ROLES))->toBeTrue()
        ->and($this->policy->viewTab($account, policyStaffAt($this->aim), EmployeePolicy::TAB_ROLES))->toBeFalse();
});

/**
 * ⚠ The guard that stops the next missing row becoming a silent default. An unknown tab is a
 * programming error, not an access decision — and an access decision is the one thing this
 * method must never invent.
 */
it('refuses an unknown tab instead of answering false', function () {
    $subject = policyStaffAt($this->aim);
    $hr = policyAccountHolding('HR', $this->ahs, policyStaffAt($this->ahs));

    expect(fn () => $this->policy->viewTab($hr, $subject, 'salary'))
        ->toThrow(InvalidArgumentException::class);

    expect(EmployeePolicy::TABS)->toHaveCount(8);
});
