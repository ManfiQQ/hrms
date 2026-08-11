<?php

use App\Exceptions\Auth\OrphanedAccountException;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\Auth\ReadScopeResolver;

/**
 * Read scope derives from the employer's position in companies.parent_company_id
 * (adr/0004 decision 1, auth-rbac.spec.md §5.4).
 */
beforeEach(function () {
    $this->resolver = app(ReadScopeResolver::class);

    $this->ahs = Company::factory()->create(['code' => 'AHS', 'name' => 'AL HADDAD SUCCESS SDN BHD']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->tursenia = Company::factory()->subsidiary($this->ahs)->create(['code' => 'TURSENIA']);

    // A shared department (company_id NULL). Every employee below is placed here so that
    // building one does not create a company of its own — EmployeeFactory's default
    // department would, and an extra company silently changes what "every company" means
    // in these assertions.
    $this->department = Department::factory()->shared()->create();
});

/** Build a STANDARD account employed by the given company. */
function standardAccountAt(Company $company): User
{
    $employee = Employee::factory()
        ->forCompany($company)
        ->create(['department_id' => test()->department->id]);

    return User::factory()->forEmployee($employee)->create();
}

it('gives a FULL account every company', function () {
    $user = User::factory()->masterAdmin()->create();

    expect($this->resolver->resolve($user))
        ->toHaveCount(3)
        ->toEqualCanonicalizing([$this->ahs->id, $this->aim->id, $this->tursenia->id]);
});

it('gives a VIEW_ONLY account every company', function () {
    $user = User::factory()->viewOnly()->create();

    expect($this->resolver->resolve($user))
        ->toEqualCanonicalizing([$this->ahs->id, $this->aim->id, $this->tursenia->id]);
});

it('gives a STANDARD account employed by the parent every company', function () {
    // This is the HR case. HR reads the whole group because it is EMPLOYED BY AHS, not
    // because of the role it holds — the account below holds no roles at all.
    $user = standardAccountAt($this->ahs);

    expect($this->resolver->resolve($user))
        ->toEqualCanonicalizing([$this->ahs->id, $this->aim->id, $this->tursenia->id]);
});

it('gives a STANDARD account employed by a subsidiary only that company', function () {
    $user = standardAccountAt($this->aim);

    expect($this->resolver->resolve($user))->toBe([$this->aim->id]);
});

it('excludes sibling subsidiaries from a subsidiary account', function () {
    $user = standardAccountAt($this->aim);

    expect($this->resolver->resolve($user))
        ->not->toContain($this->tursenia->id)
        ->not->toContain($this->ahs->id);
});

it('follows the employer, not the role, when the employer changes', function () {
    // Moving the same person from AHS to a subsidiary narrows their scope, with no other
    // change: scope is derived from the hierarchy, never stored on the account.
    $user = standardAccountAt($this->ahs);
    expect($this->resolver->resolve($user))->toHaveCount(3);

    // Assigned directly, not via update([...]): company_id is deliberately absent from
    // Employee::$fillable so it can never be set from request input
    // (employee-master.spec.md §8 test 2). Mass assignment here would silently do nothing.
    $employee = $user->employee;
    $employee->company_id = $this->aim->id;
    $employee->save();

    $this->resolver->flush();

    expect($this->resolver->resolve($user->fresh()))->toBe([$this->aim->id]);
});

/**
 * ⚠ The hierarchy is INPUT, not logic.
 *
 * adr/0004 decision 1 requires this covered by a test: "a mis-parented subsidiary would
 * grant its staff group-wide reads. The hierarchy is small and rarely changes, but it is
 * now load-bearing and must be covered by a test."
 *
 * These tests assert the dependency is real and observed. They do NOT assert desirable
 * behaviour — a subsidiary reading the whole group is a seeding fault. No amount of resolver
 * logic can detect it, because a subsidiary with a null parent is indistinguishable from the
 * parent. The value of the test is that the consequence is written down and will fail loudly
 * if anyone ever "fixes" the resolver to guess instead.
 */
it('grants group-wide reads to a subsidiary left with a null parent — the mis-parenting hazard', function () {
    $misparented = Company::factory()->create(['code' => 'SLEGHO']); // parent_company_id null

    $user = standardAccountAt($misparented);

    expect($this->resolver->resolve($user))->toHaveCount(4);
});

it('treats a subsidiary parented under another subsidiary as an ordinary subsidiary', function () {
    // Wrong parent, but still a subsidiary: parent_company_id is not null, so scope stays
    // narrow. This mis-parenting is harmless to scope — only a NULL parent widens it.
    $nested = Company::factory()->subsidiary($this->aim)->create(['code' => 'NESTED']);

    $user = standardAccountAt($nested);

    expect($this->resolver->resolve($user))->toBe([$nested->id]);
});

it('caches per request, so a hierarchy change lands on the next request not the next login', function () {
    $sub = Company::factory()->subsidiary($this->ahs)->create(['code' => 'ZISH']);
    $user = standardAccountAt($sub);

    expect($this->resolver->resolve($user))->toBe([$sub->id]);

    // Re-parent to the top. Within this request the cached answer is deliberately stale.
    $sub->update(['parent_company_id' => null]);
    expect($this->resolver->resolve($user))->toBe([$sub->id]);

    // A new request means a new container and therefore a new resolver instance, reading
    // freshly loaded models. app() would hand back the same singleton, and $user still
    // carries the relations it loaded above, so both are rebuilt here.
    expect((new ReadScopeResolver())->resolve($user->fresh()))->toHaveCount(4);
});

it('returns the same instance within a request', function () {
    expect(app(ReadScopeResolver::class))->toBe(app(ReadScopeResolver::class));
});

it('throws for a STANDARD account with no employee record', function () {
    // Impossible under BR-A20 — every staff account is created alongside its employee, and
    // the two account types that legitimately have none are FULL and VIEW_ONLY. Reaching
    // this means the data is corrupt, so it is held out at the boundary rather than
    // answered.
    $user = User::factory()->create(['employee_id' => null, 'system_access' => 'STANDARD']);

    expect(fn () => $this->resolver->resolve($user))
        ->toThrow(OrphanedAccountException::class);
});

it('does not answer a corrupt account with an empty scope', function () {
    // The distinction this test exists for: an empty scope is a VALID, ORDINARY answer. It
    // renders as an empty list and the user reads it as "there is no data yet", while the
    // real cause is an account that should not exist. Returning [] here would convert a
    // data fault into a user-facing mystery — so [] must never be the answer.
    $user = User::factory()->create(['employee_id' => null, 'system_access' => 'STANDARD']);

    expect(fn () => $this->resolver->resolve($user))->toThrow(OrphanedAccountException::class);

    // And it certainly must not quietly fall back to some company.
    try {
        $this->resolver->resolve($user);
    } catch (OrphanedAccountException $e) {
        expect($e->getMessage())->toContain('no employee record');
    }
});

it('throws when the employer cannot be loaded', function () {
    // employees.company_id is NOT NULL, so a null employer means the company row is missing
    // or soft-deleted. Scope cannot be derived from an absent hierarchy position, and
    // guessing is exactly what must not happen.
    $sub = Company::factory()->subsidiary($this->ahs)->create(['code' => 'GONE']);
    $user = standardAccountAt($sub);

    $sub->delete();

    expect(fn () => (new ReadScopeResolver())->resolve($user->fresh()))
        ->toThrow(OrphanedAccountException::class);
});
