<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\Auth\MasterAdminContext;

beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->tursenia = Company::factory()->subsidiary($this->ahs)->create(['code' => 'TURSENIA']);

    $this->sharedDept = Department::factory()->shared()->create(['name' => 'HQ Marketing']);
});

function employeeAt(Company $company): Employee
{
    return Employee::factory()
        ->forCompany($company)
        ->create(['department_id' => test()->sharedDept->id]);
}

function accountAt(Company $company): User
{
    return User::factory()->forEmployee(employeeAt($company))->create();
}

it('narrows employees to the account read scope', function () {
    $aimEmployee = employeeAt($this->aim);
    $tursEmployee = employeeAt($this->tursenia);

    $this->actingAs(accountAt($this->aim));

    $visible = Employee::query()->pluck('id');

    expect($visible)->toContain($aimEmployee->id)
        ->not->toContain($tursEmployee->id);
});

it('shows an account employed by the parent every company\'s employees', function () {
    $aimEmployee = employeeAt($this->aim);
    $tursEmployee = employeeAt($this->tursenia);

    $this->actingAs(accountAt($this->ahs));

    expect(Employee::query()->pluck('id'))
        ->toContain($aimEmployee->id)
        ->toContain($tursEmployee->id);
});

/**
 * ⚠ The inverse of the usual tenant test, and the bug most likely to ship.
 *
 * A plain equality or IN check drops shared rows and returns FEWER rows rather than
 * erroring — it presents as "the Logistics branch disappeared", not as a fault.
 */
it('keeps shared branches visible to every company', function () {
    $sharedBranch = Branch::factory()->shared()->create(['name' => 'Logistics']);
    $aimBranch = Branch::factory()->create(['company_id' => $this->aim->id]);
    $tursBranch = Branch::factory()->create(['company_id' => $this->tursenia->id]);

    $this->actingAs(accountAt($this->aim));

    $visible = Branch::query()->pluck('id');

    expect($visible)->toContain($sharedBranch->id)      // shared: visible
        ->toContain($aimBranch->id)                     // own company: visible
        ->not->toContain($tursBranch->id);              // another company: not visible
});

it('keeps shared departments visible to every company', function () {
    $aimDept = Department::factory()->create(['company_id' => $this->aim->id]);
    $tursDept = Department::factory()->create(['company_id' => $this->tursenia->id]);

    $this->actingAs(accountAt($this->aim));

    expect(Department::query()->pluck('id'))
        ->toContain($this->sharedDept->id)
        ->toContain($aimDept->id)
        ->not->toContain($tursDept->id);
});

it('does not let the shared carve-out become a blanket bypass', function () {
    // A company-dedicated row must still be hidden from other companies. Testing only the
    // shared case would turn a narrow exception into an open door.
    $tursBranch = Branch::factory()->create(['company_id' => $this->tursenia->id]);

    $this->actingAs(accountAt($this->aim));

    expect(Branch::query()->find($tursBranch->id))->toBeNull();
});

it('leaves queries unscoped when nobody is authenticated', function () {
    // Console commands, seeders, queue workers and migrations run with no user. Throwing
    // here would break every artisan command; route middleware is what protects HTTP.
    employeeAt($this->aim);
    employeeAt($this->tursenia);

    expect(Employee::query()->count())->toBe(2);
});

/**
 * ⚠ This is the test that distinguishes EXPLICIT from AMBIENT, and it is the only part of
 * adr/0005 decision 5 that can be proved today — the audit half waits on audit_logs.
 *
 * A FULL account outside the context is scoped by the ordinary mechanism. It happens to see
 * every company, because its read scope resolves to every company — but it is SCOPED, not
 * bypassed. The difference shows the moment read scope cannot express something: a
 * soft-deleted company drops out of the resolved set, so its employees vanish for a FULL
 * account outside the context, and reappear inside it.
 */
it('scopes a FULL account normally when it is outside the master admin context', function () {
    $orphanEmployee = employeeAt($this->tursenia);
    $this->tursenia->delete(); // soft-deleted: no longer in the resolved scope

    $this->actingAs(User::factory()->masterAdmin()->create());

    expect(Employee::query()->pluck('id'))->not->toContain($orphanEmployee->id);
});

it('lifts the scope only inside the master admin context', function () {
    $orphanEmployee = employeeAt($this->tursenia);
    $this->tursenia->delete();

    $this->actingAs(User::factory()->masterAdmin()->create());

    $inside = app(MasterAdminContext::class)->run(
        'Data repair: employee stranded by a soft-deleted company',
        fn () => Employee::query()->pluck('id')->all()
    );

    expect($inside)->toContain($orphanEmployee->id);
});

it('restores the scope after the context exits', function () {
    $orphanEmployee = employeeAt($this->tursenia);
    $this->tursenia->delete();

    $this->actingAs(User::factory()->masterAdmin()->create());

    app(MasterAdminContext::class)->run('repair', fn () => Employee::query()->count());

    expect(Employee::query()->pluck('id'))->not->toContain($orphanEmployee->id);
});

it('refuses a bypass with no stated reason', function () {
    // The reason is what the audit entry will carry. A bypass nobody can explain is one
    // that should not have happened.
    expect(fn () => app(MasterAdminContext::class)->run('  ', fn () => null))
        ->toThrow(RuntimeException::class);
});

it('reports the context as inactive by default', function () {
    expect(app(MasterAdminContext::class)->isActive())->toBeFalse();
});
