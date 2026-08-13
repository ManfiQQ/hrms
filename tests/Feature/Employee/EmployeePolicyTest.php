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

function policyStaffAt(Company $company, ?Department $department = null): Employee
{
    return Employee::factory()
        ->forCompany($company)
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
 */
it('gives a supervisor Employment and Personal, and nothing else', function () {
    $subject = policyStaffAt($this->aim);
    $supervisor = policyAccountHolding('SUPERVISOR', $this->aim, policyStaffAt($this->aim));

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
 */
it('refuses an HOD of a shared department another company\'s employee in that same department', function () {
    $hod = policyAccountHolding('HOD', $this->aim, policyStaffAt($this->aim, $this->shared));

    $ownCompany = policyStaffAt($this->aim, $this->shared);
    $otherCompany = policyStaffAt($this->tursenia, $this->shared);

    expect($this->policy->view($hod, $ownCompany))->toBeTrue()
        ->and($this->policy->view($hod, $otherCompany))->toBeFalse();
});

it('refuses a supervisor an employee in a different department of their own company', function () {
    $other = Department::factory()->shared()->create(['name' => 'Studio']);

    $supervisor = policyAccountHolding('SUPERVISOR', $this->aim, policyStaffAt($this->aim, $this->shared));

    expect($this->policy->view($supervisor, policyStaffAt($this->aim, $other)))->toBeFalse();
});

it('gives an employee every tab of their own record', function () {
    $employee = policyStaffAt($this->aim);
    $account = User::factory()->forEmployee($employee)->create();

    expect($this->policy->viewTab($account, $employee, EmployeePolicy::TAB_FAMILY))->toBeTrue()
        ->and($this->policy->viewTab($account, $employee, EmployeePolicy::TAB_DOCUMENTS))->toBeTrue();
});

/**
 * §6.3 — the employee retrieves six of the seven types. OTHER is withheld, which is what
 * gives it a defined purpose as the home for internal notes and investigation material
 * rather than an undifferentiated bucket.
 */
it('lets an employee open their own documents except OTHER', function () {
    $employee = policyStaffAt($this->aim);
    $account = User::factory()->forEmployee($employee)->create();

    expect($this->policy->viewDocument($account, $employee, 'IC'))->toBeTrue()
        ->and($this->policy->viewDocument($account, $employee, 'RESIGNATION_LETTER'))->toBeTrue()
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
    $subject = policyStaffAt($this->aim, $this->shared);

    foreach (['SUPERVISOR', 'MANAGER', 'HOD'] as $role) {
        $actor = policyAccountHolding($role, $this->aim, policyStaffAt($this->aim, $this->shared));

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
