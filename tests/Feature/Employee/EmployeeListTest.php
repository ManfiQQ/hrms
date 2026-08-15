<?php

use App\Livewire\Employees\EmployeeList;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\PolicyConfiguration;
use App\Models\User;
use Livewire\Livewire;

/**
 * The employee list screen — `employee-master.spec.md` §5.4, §7.
 *
 * ⚠ WHO the list shows is not tested here. That is `EmployeeListVisibilityTest`, which compares
 * `Employee::scopeVisibleTo()` against `EmployeePolicy::view()` across a population. This file
 * tests the READER'S OWN choices — the door, the search box, the filters, the page size — and
 * one thing that sits between the two: that a search can never reach past the visibility scope.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

    $this->shared = Department::factory()->shared()->create(['name' => 'Logistics']);

    // ⚠ Required by the HTTP path, not by the list. EnsureAccountIsActive reads the throttle
    // numbers from policy_configurations on every authenticated request, and they are never
    // hardcoded (conventions.md §5) — so a route test without them fails 500 on the middleware
    // before the screen is reached. Same fixture as PasswordChangeGateTest.
    foreach ([$this->ahs, $this->aim] as $company) {
        foreach (['auth.password.min_length' => '6', 'auth.throttle.tier_4.attempts' => '12'] as $key => $value) {
            PolicyConfiguration::create([
                'company_id' => $company->id,
                'key' => $key,
                'value' => $value,
                'effective_from' => now()->toDateString(),
            ]);
        }
    }

    $this->hrEmployee = Employee::factory()->forCompany($this->ahs)
        ->create(['department_id' => $this->shared->id]);
    EmployeeRole::factory()->forCompany($this->ahs)->role('HR')
        ->create(['employee_id' => $this->hrEmployee->id]);
    $this->hr = User::factory()->forEmployee($this->hrEmployee)->create();
});

function listStaff(Company $company, array $attributes = []): Employee
{
    return Employee::factory()
        ->forCompany($company)
        ->create(array_merge(['department_id' => test()->shared->id], $attributes));
}

/* ─── The door ─────────────────────────────────────────────────────────────────────── */

it('opens for an account holding an authority role', function () {
    $this->actingAs($this->hr)->get(route('employees.index'))->assertOk()->assertSee('Employees');
});

/**
 * ⚠ THE GATE IS NOT "CAN THEY SEE ANYBODY" — every account can see its own record, so that
 * test would be true for everyone and would put the screen in front of a clerk whose list is
 * one row long (EmployeePolicy::viewAny).
 */
it('is refused to ordinary staff holding no role', function () {
    $staff = User::factory()->forEmployee(listStaff($this->aim))->create();

    $this->actingAs($staff)->get(route('employees.index'))->assertForbidden();

    Livewire::actingAs($staff)->test(EmployeeList::class)->assertForbidden();
});

it('opens for a supervisor who has no subordinates at all', function () {
    // adr/0011 decision 4 made visible rather than hidden: they hold the role, so the door
    // opens; the list behind it contains only themselves, and that is the signal that nobody
    // names them in direct_supervisor_id or manager_id.
    $employee = listStaff($this->aim);
    EmployeeRole::factory()->forCompany($this->aim)->role('SUPERVISOR')
        ->create(['employee_id' => $employee->id]);
    $actor = User::factory()->forEmployee($employee)->create();

    $this->actingAs($actor)->get(route('employees.index'))->assertOk();

    Livewire::actingAs($actor)->test(EmployeeList::class)
        ->assertSee($employee->full_name)
        ->assertViewHas('employees', fn ($page) => $page->total() === 1);
});

it('shows the navigation link only to accounts that may open the list', function () {
    $staff = User::factory()->forEmployee(listStaff($this->aim))->create();

    $this->actingAs($this->hr)->get(route('dashboard'))->assertSee('Employees');
    $this->actingAs($staff)->get(route('dashboard'))->assertDontSee('Employees');
});

/* ─── Search ───────────────────────────────────────────────────────────────────────── */

/**
 * ⚠ SUBSTRING, NOT PREFIX (decision 2026-08-15). HR remembers the last name of a full Malay
 * name, so a prefix match would force them to type the first — and find nothing when they do
 * not. The cost is a full table scan, recorded on the scope itself.
 */
it('finds an employee by a fragment from the middle of their name', function () {
    listStaff($this->aim, ['full_name' => 'Nurul Aina binti Rahman']);
    listStaff($this->aim, ['full_name' => 'Siti Aminah binti Yusof']);

    Livewire::actingAs($this->hr)->test(EmployeeList::class)
        ->set('search', 'rahman')
        ->assertSee('Nurul Aina binti Rahman')
        ->assertDontSee('Siti Aminah binti Yusof');
});

it('searches all four fields with one term', function () {
    $byNickname = listStaff($this->aim, ['full_name' => 'Ahmad bin Ismail', 'nickname' => 'Mail']);
    $byEmail = listStaff($this->aim, ['full_name' => 'Chong Wei Ling', 'email' => 'mailroom@example.test']);
    $unrelated = listStaff($this->aim, ['full_name' => 'Kavitha Rajendran']);

    $component = Livewire::actingAs($this->hr)->test(EmployeeList::class)->set('search', 'mail');

    // OR across the four, never AND — one box, one term, matched anywhere (§5.4).
    $component->assertSee($byNickname->full_name)
        ->assertSee($byEmail->full_name)
        ->assertDontSee($unrelated->full_name);
});

it('finds an employee by their employee number', function () {
    $employee = listStaff($this->aim);

    Livewire::actingAs($this->hr)->test(EmployeeList::class)
        ->set('search', $employee->employee_no)
        ->assertViewHas('employees', fn ($page) => $page->total() === 1);
});

/**
 * ⚠ THE ONE TEST THAT SITS BETWEEN THIS FILE AND THE VISIBILITY GUARD, and the failure it
 * catches is the dangerous direction: MORE rows, not fewer.
 *
 * The four search conditions are ORed. Left to bind at the top level they would bind looser
 * than the visibility conditions already applied, and the search box would become a way around
 * the rule — type a name, receive an employee you may not see, correctly formatted.
 *
 * ⚠ WHAT MAKES THIS RED, AND WHAT DOES NOT — worth knowing before trusting it. Deleting the
 * parentheses inside `scopeMatchingSearch()` does NOT: Laravel groups constraints added inside
 * a local scope automatically, so the SQL comes out the same. What does is moving the search
 * OUT of the scope and inlining those four `orWhere`s in this component — verified, and it is
 * the mistake this test exists for, because "simplify it into the caller" is the plausible
 * future edit and §5.4 forbids it for precisely this reason.
 */
it('never lets a search reach an employee outside the visible set', function () {
    $supervisorEmployee = listStaff($this->aim);
    EmployeeRole::factory()->forCompany($this->aim)->role('SUPERVISOR')
        ->create(['employee_id' => $supervisorEmployee->id]);
    $supervisor = User::factory()->forEmployee($supervisorEmployee)->create();

    // Same company, reports to nobody — invisible to this supervisor (adr/0011 decision 4).
    $invisible = listStaff($this->aim, ['full_name' => 'Zulkifli bin Hassan']);

    Livewire::actingAs($supervisor)->test(EmployeeList::class)
        ->set('search', 'Zulkifli')
        ->assertDontSee($invisible->full_name)
        ->assertViewHas('employees', fn ($page) => $page->total() === 0);
});

it('treats a LIKE wildcard as text rather than a wildcard', function () {
    listStaff($this->aim, ['full_name' => 'Faridah binti Omar']);

    Livewire::actingAs($this->hr)->test(EmployeeList::class)
        ->set('search', '%')
        ->assertViewHas('employees', fn ($page) => $page->total() === 0);
});

/* ─── Filters ──────────────────────────────────────────────────────────────────────── */

it('narrows by staff status without touching what is visible', function () {
    listStaff($this->aim, ['staff_status' => 'PROBATION']);
    $confirmed = listStaff($this->aim, ['staff_status' => 'CONFIRMED']);

    Livewire::actingAs($this->hr)->test(EmployeeList::class)
        ->set('staffStatus', 'CONFIRMED')
        ->assertViewHas('employees', fn ($page) => $page->pluck('id')->all() === [$confirmed->id]);
});

it('clears every filter and the search term together', function () {
    listStaff($this->aim, ['staff_status' => 'PROBATION']);

    Livewire::actingAs($this->hr)->test(EmployeeList::class)
        ->set('search', 'nobody')
        ->set('staffStatus', 'CONFIRMED')
        ->assertViewHas('employees', fn ($page) => $page->total() === 0)
        ->call('clearFilters')
        ->assertViewHas('employees', fn ($page) => $page->total() > 0);
});

/**
 * ⚠ TWO ACCOUNTS SEE DIFFERENT FORMS, DELIBERATELY. A select with one option is a control that
 * cannot change the answer, and a label naming the one company answers a question its own staff
 * never ask. Anyone "tidying" the two layouts into one restores the dead control.
 */
it('hides the company filter from an account that reads one company', function () {
    $subsidiaryHr = listStaff($this->aim);
    EmployeeRole::factory()->forCompany($this->aim)->role('HR')
        ->create(['employee_id' => $subsidiaryHr->id]);
    $actor = User::factory()->forEmployee($subsidiaryHr)->create();

    Livewire::actingAs($actor)->test(EmployeeList::class)
        ->assertSet('companyId', '')
        ->assertDontSee('All companies');

    // The same screen for a group-scoped account does show it.
    Livewire::actingAs($this->hr)->test(EmployeeList::class)->assertSee('All companies');
});

/**
 * ⚠ A HIDDEN FILTER MUST ALSO BE AN INERT ONE. The control is absent from the form, so the only
 * way `companyId` arrives set is a crafted request — and a filter that still applied would let
 * a subsidiary account probe which company ids exist by watching the row count change.
 */
it('ignores a company filter supplied by an account that cannot see the control', function () {
    $subsidiaryHr = listStaff($this->aim);
    EmployeeRole::factory()->forCompany($this->aim)->role('HR')
        ->create(['employee_id' => $subsidiaryHr->id]);
    $actor = User::factory()->forEmployee($subsidiaryHr)->create();

    $before = Livewire::actingAs($actor)->test(EmployeeList::class)
        ->viewData('employees')->total();

    Livewire::actingAs($actor)->test(EmployeeList::class)
        ->set('companyId', (string) $this->ahs->id)
        ->assertViewHas('employees', fn ($page) => $page->total() === $before);
});

/* ─── Pagination ───────────────────────────────────────────────────────────────────── */

it('paginates at twenty-five and keeps the rest reachable', function () {
    Employee::factory()->count(30)->forCompany($this->aim)
        ->create(['department_id' => $this->shared->id]);

    $component = Livewire::actingAs($this->hr)->test(EmployeeList::class);

    // 30 created here plus the HR employee from beforeEach.
    $component->assertViewHas('employees', fn ($page) => $page->count() === 25 && $page->total() === 31);

    $component->call('gotoPage', 2)
        ->assertViewHas('employees', fn ($page) => $page->count() === 6);
});

/**
 * ⚠ Narrowing while on a later page must return to page one. Without it the reader sees an
 * empty table with a working paginator, which reads as "nothing matches" when the truth is
 * "there is no page 2 any more".
 */
it('returns to the first page when the search changes', function () {
    Employee::factory()->count(30)->forCompany($this->aim)
        ->create(['department_id' => $this->shared->id]);

    Livewire::actingAs($this->hr)->test(EmployeeList::class)
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('search', 'a')
        ->assertSet('paginators.page', 1);
});

/* ─── What the list shows, and what it must not ────────────────────────────────────── */

/**
 * ⚠ NONE OF `adr/0013`'s TWELVE COLUMNS APPEAR HERE. They are the Personal tab's, behind a
 * per-tab check (§6.2) — a list identifies people, it does not display their identity. This
 * asserts the two that would be most damaging to leak into a screen every supervisor can open.
 */
it('shows the six agreed columns and no identity data', function () {
    $employee = listStaff($this->aim, [
        'full_name' => 'Nurul Aina binti Rahman',
        'ic_no' => '900101145566',
        'bank_account_no' => '1234567890',
    ]);

    Livewire::actingAs($this->hr)->test(EmployeeList::class)
        ->assertSee($employee->employee_no)
        ->assertSee($employee->full_name)
        ->assertSee($employee->staff_status)
        ->assertDontSee('900101145566')
        ->assertDontSee('1234567890');
});

/**
 * ⚠ ASSERTED AS RELATIVE ORDER, NOT AS "FIRST ROW", AND THE FIRST VERSION OF THIS TEST WAS
 * FLAKY FOR EXACTLY THAT REASON. The HR employee in `beforeEach` carries a faker name, so
 * whether it sorts before "Aisyah" is a coin toss — the test passed four runs and failed the
 * fifth, on a restore where nothing was broken. A fixture nobody chose must never be part of
 * the assertion.
 */
it('sorts by name so HR can look somebody up', function () {
    listStaff($this->aim, ['full_name' => 'Zulkifli bin Hassan']);
    listStaff($this->aim, ['full_name' => 'Aisyah binti Karim']);

    Livewire::actingAs($this->hr)->test(EmployeeList::class)
        ->assertViewHas('employees', function ($page) {
            $names = $page->pluck('full_name')->all();

            return array_search('Aisyah binti Karim', $names, true)
                < array_search('Zulkifli bin Hassan', $names, true);
        });
});
