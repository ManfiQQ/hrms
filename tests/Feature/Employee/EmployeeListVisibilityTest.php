<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\User;
use App\Policies\EmployeePolicy;
use Illuminate\Support\Collection;

/**
 * THE CONSENT GUARD `adr/0011` REQUIRES — the list scope and the per-record policy must agree.
 *
 * `EmployeePolicy::view()` answers *(actor, subject) → bool*. `Employee::scopeVisibleTo()`
 * answers *(actor) → set*. They express one rule in two forms and cannot share an
 * implementation, so the only thing that can hold them together is a comparison of outcomes.
 *
 * ⚠ IT COMPARES AGAINST `view()`, NOT `viewTab()`, AND THAT IS WHAT REMOVES THE PROXY PROBLEM.
 * `adr/0011` deferred this guard partly because a comparison against `viewTab()` must pick one
 * tab to stand for the whole method, and that proxy is an assumption nothing checks. `view()`
 * needs no proxy: it IS `viewTab(…, TAB_EMPLOYMENT)` by definition, and it asks exactly the
 * question a list asks — *may this account open this record at all?*
 *
 * ═══ WHAT THIS GUARD CANNOT CATCH ═══════════════════════════════════════════════════════
 *
 * 1. IT IS ONLY AS WIDE AS THE POPULATION. A relationship shape absent from the fixtures
 *    below cannot disagree here. Every branch of the rule must appear in `population()`, and
 *    the collection is asserted non-empty because a guard over an empty set passes forever
 *    while checking nothing (`conventions.md` §9).
 *
 * 2. IT SAYS NOTHING ABOUT THE OTHER SEVEN TABS. Agreement on `view()` is agreement on
 *    Employment. Family, Documents and the rest are `viewTab()`'s business and are covered by
 *    `EmployeePolicyTest` — a list does not answer tab questions, so this guard must not be
 *    read as covering them.
 *
 * 3. ⚠ AGREEMENT IS NOT CORRECTNESS, AND THIS IS THE LIMIT THAT MATTERS MOST. If the policy
 *    and the scope are wrong in the SAME direction — the BR-8 operands flipped in both, so an
 *    employee reads their own supervisor — the two agree perfectly and this stays green. Only
 *    `EmployeePolicyTest`, which asserts the direction of the rule against fixed expectations,
 *    holds that half. This guard proves the two forms are the same rule; it cannot prove the
 *    rule is the right one.
 *
 * 4. IT COMPARES WHOLE RESULT SETS, NEVER A PAGE. The list screen paginates; a guard that
 *    read page one would pass while page two leaked. Nothing below may acquire a `paginate()`
 *    or a `limit()`.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->tursenia = Company::factory()->subsidiary($this->ahs)->create(['code' => 'TURSENIA']);

    // Shared: belongs to no single company, and holds staff from several (adr/0002).
    $this->shared = Department::factory()->shared()->create(['name' => 'Logistics']);

    $this->policy = app(EmployeePolicy::class);

    // ⚠ The supervisor below is employed by AIM, so the interesting cases are all AIM-side:
    // read scope is one company, and the reporting line is the only thing doing any further
    // narrowing. The parent-employed case, where scope is group-wide and the COMPANY half of
    // the bound does the work instead, has its own test further down.
    //
    // ⚠ The actor's OWN manager, built by the factory BEFORE the actor — never by an
    // ->update() afterwards. An update() here is refused by AuthorshipObserver, which has no
    // actor at this point in the fixture, and the test would go red for a reason that has
    // nothing to do with the rule under test. That is `conventions.md` §9's other direction:
    // red for the wrong reason is as undetectable as green for the wrong reason, and rather
    // more convincing.
    $this->supervisorsOwnManager = listStaffAt($this->aim);

    $this->supervisorEmployee = Employee::factory()->forCompany($this->aim)
        ->managedBy($this->supervisorsOwnManager)
        ->create(['department_id' => $this->shared->id]);

    EmployeeRole::factory()->forCompany($this->aim)->role('SUPERVISOR')
        ->create(['employee_id' => $this->supervisorEmployee->id]);
    $this->supervisor = User::factory()->forEmployee($this->supervisorEmployee)->create();
});

function listStaffAt(Company $company): Employee
{
    return Employee::factory()
        ->forCompany($company)
        ->create(['department_id' => test()->shared->id]);
}

/**
 * Every relationship shape the rule branches on, built once and compared in full.
 *
 * ⚠ Read WITHOUT global scopes: the population is the universe of candidates the policy is
 * asked about, and applying the actor's own tenant scope to it would narrow both sides of the
 * comparison by the same amount and hide a disagreement.
 *
 * @return Collection<int, Employee>
 */
function population(): Collection
{
    return Employee::withoutGlobalScopes()->get();
}

/**
 * The set the LIST shows this actor — the whole result, never a page (limit 4 above).
 *
 * `actingAs` because `TenantScope` reads the authenticated user; the scope under test reads
 * the actor it is handed. Both must be the same account or this compares two different
 * questions.
 */
function listedFor(User $actor): array
{
    test()->actingAs($actor);

    return Employee::query()->visibleTo($actor)->pluck('id')->sort()->values()->all();
}

/** The set the POLICY admits this actor to, asked one record at a time. */
function admittedFor(User $actor): array
{
    return population()
        ->filter(fn (Employee $employee) => test()->policy->view($actor, $employee))
        ->pluck('id')->sort()->values()->all();
}

/**
 * The population, spelled out so a reader can see which branches exist — and so limit 1 above
 * is a statement about something concrete rather than a disclaimer.
 */
function buildPopulation(): array
{
    $supervisor = test()->supervisorEmployee;

    return [
        'reports via direct_supervisor_id' => Employee::factory()->forCompany(test()->aim)
            ->reportingTo($supervisor)->create(['department_id' => test()->shared->id]),

        'reports via manager_id' => Employee::factory()->forCompany(test()->aim)
            ->managedBy($supervisor)->create(['department_id' => test()->shared->id]),

        'reports via both columns' => Employee::factory()->forCompany(test()->aim)
            ->reportingTo($supervisor)->managedBy($supervisor)
            ->create(['department_id' => test()->shared->id]),

        // ⚠ adr/0011 decision 4 — read by nobody below HR, and deliberately so.
        'reports to nobody' => listStaffAt(test()->aim),

        // ⚠ The company half of the double bound. Names the actor as supervisor but is
        // employed elsewhere: a shared department makes them look like colleagues.
        'reports to the actor, employed by another company' => Employee::factory()
            ->forCompany(test()->tursenia)->reportingTo($supervisor)
            ->create(['department_id' => test()->shared->id]),

        // ⚠ The direction of the rule, and the operand order it protects. The ACTOR names
        // this person in `manager_id`, not the reverse — so the actor must NOT see them.
        // Flipping the comparison in the scope would list an employee's own manager.
        'the actor own manager' => test()->supervisorsOwnManager,

        'a colleague at the same company reporting to somebody else' => Employee::factory()
            ->forCompany(test()->aim)->reportingTo(listStaffAt(test()->aim))
            ->create(['department_id' => test()->shared->id]),
    ];
}

it('finds the population it is meant to be comparing over', function () {
    $shapes = buildPopulation();

    // conventions.md §9 — a guard over a collection must assert the collection is not empty,
    // or it passes forever while iterating nothing.
    expect($shapes)->not->toBeEmpty()
        ->and(population()->count())->toBeGreaterThan(count($shapes));
});

it('shows a supervisor exactly the records the policy admits them to', function () {
    buildPopulation();

    $listed = listedFor($this->supervisor);

    expect($listed)->not->toBeEmpty()
        ->and($listed)->toBe(admittedFor($this->supervisor))
        // ⚠ Their own record, which viewTab() grants before any role check. A scope that
        // omitted it would disagree with the policy on the one row every actor can see.
        ->and($listed)->toContain($this->supervisorEmployee->id);
});

it('shows a supervisor with no subordinates only themselves', function () {
    buildPopulation();

    $lonely = listStaffAt($this->aim);
    EmployeeRole::factory()->forCompany($this->aim)->role('MANAGER')
        ->create(['employee_id' => $lonely->id]);
    $actor = User::factory()->forEmployee($lonely)->create();

    // adr/0011 decision 4, visible rather than silent: an employee whose BR-8 columns are
    // empty is read by nobody below HR, so a manager nobody names sees one row.
    expect(listedFor($actor))->toBe([$lonely->id])
        ->and(listedFor($actor))->toBe(admittedFor($actor));
});

/**
 * ⚠ THE ONLY CASE WHERE THE COMPANY HALF OF THE DOUBLE BOUND DOES ANY WORK, and it was missing
 * from the population until the breaks were written — limit 1 above, caught in the act.
 *
 * For the AIM-employed supervisor in `beforeEach`, `TenantScope` has already excluded every
 * other company, so removing the company bound from the scope changes nothing and the guard
 * stays green. A supervisor employed by the PARENT reads the whole group by scope, so a
 * subordinate naming them from another company survives `TenantScope` and only
 * `employees.company_id` on both sides keeps them out (`adr/0002` decision 4).
 */
it('does not show a parent-employed supervisor their subordinates at other companies', function () {
    buildPopulation();

    $ahsSupervisor = listStaffAt($this->ahs);
    EmployeeRole::factory()->forCompany($this->ahs)->role('SUPERVISOR')
        ->create(['employee_id' => $ahsSupervisor->id]);
    $actor = User::factory()->forEmployee($ahsSupervisor)->create();

    $sameCompany = Employee::factory()->forCompany($this->ahs)->reportingTo($ahsSupervisor)
        ->create(['department_id' => $this->shared->id]);

    $crossCompany = Employee::factory()->forCompany($this->aim)->reportingTo($ahsSupervisor)
        ->create(['department_id' => $this->shared->id]);

    $listed = listedFor($actor);

    expect($listed)->toBe(admittedFor($actor))
        ->and($listed)->toContain($sameCompany->id)
        ->and($listed)->not->toContain($crossCompany->id);
});

it('shows ordinary staff only their own record', function () {
    buildPopulation();

    $staff = listStaffAt($this->aim);
    $actor = User::factory()->forEmployee($staff)->create();

    expect(listedFor($actor))->toBe([$staff->id])
        ->and(listedFor($actor))->toBe(admittedFor($actor));
});

/**
 * ⚠ THE ROW THAT BLOCKS HR ON DAY ONE IF THE SCOPE IS WRONG (spec §8 test 1g). A narrowing
 * filter returns FEWER ROWS rather than erroring, so an over-eager scope hides employees from
 * HR with nothing to notice.
 */
it('shows an AHS-employed HR every employee in the group', function () {
    buildPopulation();

    $hrEmployee = listStaffAt($this->ahs);
    EmployeeRole::factory()->forCompany($this->ahs)->role('HR')
        ->create(['employee_id' => $hrEmployee->id]);
    $actor = User::factory()->forEmployee($hrEmployee)->create();

    $listed = listedFor($actor);

    expect($listed)->toBe(admittedFor($actor))
        ->and($listed)->toHaveCount(population()->count());
});

/** Scope follows the employer's position in the hierarchy, not the role (§8 test 1h). */
it('shows a subsidiary-employed HR that subsidiary only', function () {
    buildPopulation();

    $hrEmployee = listStaffAt($this->aim);
    EmployeeRole::factory()->forCompany($this->aim)->role('HR')
        ->create(['employee_id' => $hrEmployee->id]);
    $actor = User::factory()->forEmployee($hrEmployee)->create();

    $listed = listedFor($actor);

    expect($listed)->toBe(admittedFor($actor))
        ->and(Employee::withoutGlobalScopes()->whereIn('id', $listed)->pluck('company_id')->unique()->all())
        ->toBe([$this->aim->id]);
});

it('shows a Master Admin and a VIEW_ONLY account the whole group', function () {
    buildPopulation();

    foreach ([User::factory()->masterAdmin()->create(), User::factory()->viewOnly()->create()] as $actor) {
        expect(listedFor($actor))->toBe(admittedFor($actor))
            ->and(listedFor($actor))->toHaveCount(population()->count());
    }
});
