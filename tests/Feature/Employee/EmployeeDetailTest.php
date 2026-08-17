<?php

use App\Livewire\Employees\EmployeeDetail;
use App\Livewire\Employees\EmployeeList;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\EmployeeStatusHistory;
use App\Models\PolicyConfiguration;
use App\Models\User;
use App\Policies\EmployeePolicy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The employee detail screen — `employee-master.spec.md` §7 and §7.1.
 *
 * ⚠ WHICH FIELDS the Personal tab yields is `PersonalFieldTieringTest`, against the policy.
 * This file tests the SCREEN: the door, the tab strip, what a crafted `?tab=` does, and that
 * the withheld fields are absent from the rendered response rather than merely absent from a
 * list. The two are different questions and a screen can get the second one wrong on its own.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

    $this->shared = Department::factory()->shared()->create(['name' => 'Logistics']);

    // Required by the HTTP path: EnsureAccountIsActive reads these on every authenticated
    // request, and they are never hardcoded (conventions.md §5). Same fixture as EmployeeListTest.
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

    $this->hrEmployee = detailStaffAt($this->ahs);
    $this->hr = detailAccountHolding('HR', $this->ahs, $this->hrEmployee);

    $this->supervisorEmployee = detailStaffAt($this->aim);
    $this->supervisor = detailAccountHolding('SUPERVISOR', $this->aim, $this->supervisorEmployee);
});

function detailStaffAt(Company $company, array $attributes = []): Employee
{
    return Employee::factory()->forCompany($company)
        ->create($attributes + ['department_id' => test()->shared->id]);
}

/** ⚠ Names the actor in `direct_supervisor_id`, or the outer bound refuses before any tab question. */
function detailSubordinateOf(Employee $supervisor, Company $company, array $attributes = []): Employee
{
    return Employee::factory()->forCompany($company)->reportingTo($supervisor)
        ->create($attributes + ['department_id' => test()->shared->id]);
}

function detailAccountHolding(string $role, Company $roleAt, Employee $employee): User
{
    EmployeeRole::factory()->forCompany($roleAt)->role($role)->create(['employee_id' => $employee->id]);

    return User::factory()->forEmployee($employee)->passwordChanged()->create();
}

it('opens the screen for an account the policy admits', function () {
    $this->actingAs($this->hr)
        ->get(route('employees.show', detailStaffAt($this->aim)))
        ->assertOk();
});

/**
 * ⚠ TWO REFUSALS THAT MUST NOT BE THE SAME. `TenantScope` applies to route model binding, so
 * an out-of-scope record is a 404 — the URL cannot be used to confirm the id exists. An
 * in-scope record the reader may not open is a 403.
 */
it('answers 404 out of scope and 403 in scope but unauthorised', function () {
    $subsidiaryHr = detailAccountHolding('HR', $this->aim, detailStaffAt($this->aim));

    $this->actingAs($subsidiaryHr)
        ->get(route('employees.show', detailStaffAt($this->ahs)))
        ->assertNotFound();

    // Same company as the supervisor, but nobody's subordinate — adr/0011 decision 4.
    $this->actingAs($this->supervisor)
        ->get(route('employees.show', detailStaffAt($this->aim)))
        ->assertForbidden();
});

it('shows a supervisor two tabs and the administrative tier all eight', function () {
    $subordinate = detailSubordinateOf($this->supervisorEmployee, $this->aim);

    Livewire::actingAs($this->supervisor)->test(EmployeeDetail::class, ['employee' => $subordinate])
        ->assertSet('tab', EmployeePolicy::TAB_EMPLOYMENT)
        ->tap(fn ($component) => expect($component->instance()->visibleTabs)
            ->toBe([EmployeePolicy::TAB_EMPLOYMENT, EmployeePolicy::TAB_PERSONAL]));

    Livewire::actingAs($this->hr)->test(EmployeeDetail::class, ['employee' => $subordinate])
        ->tap(fn ($component) => expect($component->instance()->visibleTabs)
            ->toBe(EmployeePolicy::TABS));
});

/**
 * ⚠ THE POINT OF THE TIERING, ASSERTED AGAINST THE RENDERED RESPONSE. The field list is the
 * policy's business; this asserts the SCREEN honours it — a withheld value must be absent
 * from the HTML, not merely absent from an array.
 *
 * The values are distinctive so `assertDontSee` cannot pass against an empty render, and each
 * negative is paired with HR seeing the same record.
 */
it('withholds the identity and statutory fields from a supervisor and shows them to HR', function () {
    $subordinate = detailSubordinateOf($this->supervisorEmployee, $this->aim, [
        'ic_no' => '900101145511',
        'bank_account_no' => '5590011223344',
        'address' => 'No 8 Jalan Distinctive, Shah Alam',
        'nickname' => 'Distinctive-Nickname',
    ]);

    Livewire::actingAs($this->supervisor)->test(EmployeeDetail::class, ['employee' => $subordinate])
        ->call('selectTab', EmployeePolicy::TAB_PERSONAL)
        ->assertSee('Distinctive-Nickname')
        ->assertDontSee('900101145511')
        ->assertDontSee('5590011223344')
        ->assertDontSee('Jalan Distinctive');

    Livewire::actingAs($this->hr)->test(EmployeeDetail::class, ['employee' => $subordinate])
        ->call('selectTab', EmployeePolicy::TAB_PERSONAL)
        ->assertSee('Distinctive-Nickname')
        ->assertSee('900101145511')
        ->assertSee('5590011223344')
        ->assertSee('Jalan Distinctive');
});

/**
 * ⚠ THE GUARD BETWEEN A TYPED URL AND A DELIBERATE EXCEPTION. `viewTab()` THROWS on a name
 * outside TABS (`adr/0004`'s 2026-08-13 amendment) — that throw exists so a missing permission
 * row announces itself, and it must never be reachable from the query string. Remove the
 * `in_array` check in `resolveTab()` and this case becomes a 500.
 */
it('falls back to Employment for a tab name that does not exist, without reaching the policy', function () {
    $subject = detailStaffAt($this->aim);

    $this->actingAs($this->hr)
        ->get(route('employees.show', $subject).'?tab=salary')
        ->assertOk()
        ->assertSee('Employer (payroll):');

    Livewire::actingAs($this->hr)->test(EmployeeDetail::class, ['employee' => $subject, 'tab' => 'salary'])
        ->tap(fn ($component) => expect($component->instance()->activeTab)
            ->toBe(EmployeePolicy::TAB_EMPLOYMENT));
});

/** A REAL tab this account may not read is refused by the policy, not by the name check. */
it('falls back to Employment for a real tab the reader may not open', function () {
    $subordinate = detailSubordinateOf($this->supervisorEmployee, $this->aim);

    Livewire::actingAs($this->supervisor)
        ->test(EmployeeDetail::class, ['employee' => $subordinate, 'tab' => EmployeePolicy::TAB_FAMILY])
        ->tap(fn ($component) => expect($component->instance()->activeTab)
            ->toBe(EmployeePolicy::TAB_EMPLOYMENT));
});

it('renders both adr/0013 flags on the Employment tab, and neither number with them', function () {
    $subject = detailStaffAt($this->aim, [
        'staff_status' => 'CONFIRMED',
        'permit_expiry' => now()->subMonth(),
        'epf_no' => null,
        'socso_no' => 'SOCSO-DISTINCTIVE-1',
    ]);

    Livewire::actingAs($this->supervisor)->test(EmployeeDetail::class, ['employee' => $subject])
        ->assertStatus(403);

    Livewire::actingAs($this->hr)->test(EmployeeDetail::class, ['employee' => $subject])
        ->assertSee('Work permit expired')
        ->assertSee('Statutory registration incomplete')
        // The flag states that a gap exists; the number itself is Personal-tab data (§7.1).
        ->assertDontSee('SOCSO-DISTINCTIVE-1');
});

it('says plainly that the documents path is not built, and renders no photo anywhere', function () {
    Livewire::actingAs($this->hr)->test(EmployeeDetail::class, ['employee' => detailStaffAt($this->aim)])
        ->call('selectTab', EmployeePolicy::TAB_DOCUMENTS)
        ->assertSee('Documents are not available on this screen yet.')
        ->assertDontSee('<img', false);
});

/** ⚠ Read-only (adr/0014). A control the policy would refuse must not be rendered at all. */
it('renders no write control on any tab the administrative tier can open', function () {
    $subject = detailStaffAt($this->aim);

    foreach (EmployeePolicy::TABS as $tab) {
        Livewire::actingAs($this->hr)->test(EmployeeDetail::class, ['employee' => $subject])
            ->call('selectTab', $tab)
            ->assertDontSee('Grant')
            ->assertDontSee('Revoke')
            ->assertDontSee('Archive')
            ->assertDontSee('<form', false);
    }
});

/**
 * ⚠ THIS GUARD EXISTS BECAUSE THE ALTERNATIVE FAILS ON THE FIRST USER, NOT THE FIRST
 * DEVELOPER. A key added to `PERSONAL_FIELDS_ALL` without a label here is an undefined-index
 * error — loud, but only once somebody renders the Personal tab as a reader entitled to that
 * field. It ships green until then, and `conventions.md` §9's newest finding is the evidence
 * that "some tab, some tier, some reader" is not a path a suite necessarily walks.
 *
 * Both directions. An orphan label is a field that was REMOVED with its label left behind,
 * which is how a list starts describing a column that no longer exists.
 */
it('has a Personal label for every policy field, and no orphans', function () {
    $fields = EmployeePolicy::PERSONAL_FIELDS_ALL;
    $labelled = array_keys(EmployeeDetail::PERSONAL_LABELS);

    expect($fields)->not->toBeEmpty()
        ->and(array_diff($fields, $labelled))->toBe([])
        ->and(array_diff($labelled, $fields))->toBe([]);
});

it('renders the merged timeline on the Status history tab, both sources at once', function () {
    $subject = detailStaffAt($this->aim);

    EmployeeStatusHistory::factory()->create([
        'employee_id' => $subject->id,
        'company_id' => $this->aim->id,
        'change_type' => 'STAFF_STATUS',
        'new_label' => 'CONFIRMED',
        'effective_date' => '2026-03-01',
    ]);
    EmployeeRole::factory()->forCompany($this->aim)->role('MANAGER')->create([
        'employee_id' => $subject->id,
        'effective_date' => '2026-01-15',
        'revoked_date' => '2026-08-08',
    ]);

    Livewire::actingAs($this->hr)->test(EmployeeDetail::class, ['employee' => $subject])
        ->call('selectTab', EmployeePolicy::TAB_STATUS_HISTORY)
        ->assertSee('Role → Manager')
        ->assertSee('Status → CONFIRMED')
        ->assertSee('Manager revoked')
        ->assertSee('15 Jan 2026')
        ->assertSee('08 Aug 2026')
        // §7: each entry indicates its source, and every line names its company.
        ->assertSee('employee_status_history')
        ->assertSee('employee_roles')
        ->assertSee($this->aim->name);
});

it('links every list row to its detail screen', function () {
    $subordinate = detailSubordinateOf($this->supervisorEmployee, $this->aim);

    Livewire::actingAs($this->supervisor)->test(EmployeeList::class)
        ->assertSee(route('employees.show', $subordinate), false);
});

/**
 * ⚠ A CEILING, NOT A MEASUREMENT OF WHAT IS OPTIMAL. The tab strip costs one `viewTab()` per
 * tab, each of which can cost a role query per company in the read scope, and the active tab
 * loads its own relations. Without eager loading the Employment tab issues a query per
 * relation it touches; this bound goes red when one is removed, which is the only reason it
 * is here. Measured at 20 on 2026-08-17 with the fixture below; the bound is 25 so ordinary
 * fixture drift does not go red, and removing an eager load costs far more than five.
 */
it('renders the Employment tab within a bounded number of queries', function () {
    $subject = detailStaffAt($this->aim);
    EmployeeRole::factory()->forCompany($this->aim)->role('MANAGER')->create(['employee_id' => $subject->id]);

    $this->actingAs($this->hr);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    Livewire::actingAs($this->hr)->test(EmployeeDetail::class, ['employee' => $subject]);

    expect($queries)->toBeGreaterThan(0)->toBeLessThanOrEqual(25);
});
