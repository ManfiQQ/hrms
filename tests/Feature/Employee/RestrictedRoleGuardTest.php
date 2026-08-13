<?php

use App\Exceptions\Employee\RestrictedRoleGrantException;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\User;
use App\Services\Audit\AuthorshipContext;
use App\Services\Auth\RestrictedRoleContext;

/**
 * BR-16 — only Master Admin may grant a restricted role (`adr/0003` decision 3).
 *
 * ⚠ EVERY TEST HERE GOES THROUGH EmployeeRole::create(), NOT THE FACTORY. The factory enters
 * RestrictedRoleContext deliberately so ordinary fixtures can build an HR without logging a
 * Master Admin in first — so a test that used it would prove only that the bypass works.
 * These assert the guard on the raw write path, which is the path a future module will take
 * without reading BR-16 first.
 *
 * ⚠ WHAT IS AT STAKE. `adr/0003` decision 5 is unconditional: only ACCOUNT reads salary, no
 * HR ever, at any scope. That rule is only enforceable if HR cannot grant itself ACCOUNT —
 * otherwise it is not violated, it is **walked around through the front door**, and it looks
 * like ordinary HR activity in the audit log.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->dept = Department::factory()->shared()->create(['name' => 'HQ']);

    $this->employee = Employee::factory()
        ->forCompany($this->ahs)
        ->create(['department_id' => $this->dept->id]);

    // ⚠ employee_roles.assigned_by is NOT NULL, and entering RestrictedRoleContext does not
    // change that: the context lifts BR-16's AUTHORITY check, never the schema's requirement
    // that somebody be named as the granter. A seeder names the Master Admin it just created;
    // these tests name this account.
    $this->granter = User::factory()->create(['system_access' => 'FULL', 'employee_id' => null]);
});

/**
 * ⚠ WRAPPED IN AuthorshipContext SO THE GUARD UNDER TEST IS THE GUARD UNDER TEST — not to
 * make anything pass. Read that carefully before deleting it as a lazy shortcut.
 *
 * Two guards now sit on this same write. AuthorshipObserver refuses a row with no actor
 * (adr/0009), and the BR-16 hook refuses a restricted role granted by anyone but Master Admin.
 * Authorship fires first. Left unwrapped, every test below would go red on the authorship
 * error, BR-16 would never run, and — the part that matters — DELETING THE BR-16 HOOK
 * ENTIRELY WOULD NOT CHANGE A SINGLE COLOUR HERE. The suite would look like it guarded BR-16
 * while guarding nothing of the sort.
 *
 * Naming an actor satisfies the outer guard and leaves the inner one as the only thing these
 * tests can fail on. conventions.md §9 records the general rule.
 *
 * ⚠ The actor is NOT logged in — auth() stays null where the test wants it null, because
 * BR-16 has its own separate answer for the unauthenticated case.
 */
function grantDirectly(string $role): EmployeeRole
{
    return app(AuthorshipContext::class)->run(
        test()->granter,
        'Fixture attribution, so BR-16 is the rule these tests exercise.',
        fn () => EmployeeRole::create([
        'employee_id' => test()->employee->id,
        'company_id' => test()->ahs->id,
        'role' => $role,
        'effective_date' => now()->toDateString(),
            'assigned_by' => auth()->id() ?? test()->granter->id,
        ])
    );
}

function anHrAccount(): User
{
    $hr = Employee::factory()
        ->forCompany(test()->ahs)
        ->create(['department_id' => test()->dept->id]);

    EmployeeRole::factory()->forCompany(test()->ahs)->role('HR')->create(['employee_id' => $hr->id]);

    return User::factory()->forEmployee($hr)->create();
}

/**
 * ⚠ THE FIRST OF THE TWO BREAKS THIS GUARD EXISTS FOR: an ordinary HR reaching for ACCOUNT.
 */
it('refuses ACCOUNT to an authenticated HR', function () {
    $this->actingAs(anHrAccount());

    expect(fn () => grantDirectly('ACCOUNT'))->toThrow(RestrictedRoleGrantException::class);

    expect(EmployeeRole::query()->where('role', 'ACCOUNT')->exists())->toBeFalse();
});

/**
 * ⚠ THE SECOND: no authenticated user at all — a seeder, a console command, a queue job, a
 * tinker session.
 *
 * Null is REFUSED rather than waved through. If absence of a user meant "allowed", `null`
 * would be the shortcut past BR-16 that every background process takes without anyone
 * deciding it should — the ambient bypass `adr/0005` decision 5 rejects in its own domain.
 */
it('refuses ACCOUNT when nobody is logged in at all', function () {
    expect(auth()->user())->toBeNull();

    expect(fn () => grantDirectly('ACCOUNT'))->toThrow(RestrictedRoleGrantException::class);

    expect(EmployeeRole::query()->where('role', 'ACCOUNT')->exists())->toBeFalse();
});

it('refuses every one of the four restricted roles, not just ACCOUNT', function () {
    $this->actingAs(anHrAccount());

    foreach (EmployeeRole::RESTRICTED as $role) {
        expect(fn () => grantDirectly($role))->toThrow(RestrictedRoleGrantException::class);
    }

    expect(EmployeeRole::RESTRICTED)->toEqualCanonicalizing(['ACCOUNT', 'HR', 'ASSISTANT_DIRECTOR', 'HOD']);
});

/**
 * MANAGER and SUPERVISOR are deliberately unrestricted — routine appointments that change
 * often, spanning one approval stage and no sensitive data. Routing them through Master Admin
 * would pull that account into daily HR work.
 */
it('lets HR grant the two unrestricted roles', function () {
    $this->actingAs(anHrAccount());

    grantDirectly('MANAGER');
    grantDirectly('SUPERVISOR');

    expect(EmployeeRole::query()->where('employee_id', $this->employee->id)->count())->toBe(2);
});

it('lets Master Admin grant a restricted role', function () {
    $this->actingAs(User::factory()->create(['system_access' => 'FULL', 'employee_id' => null]));

    grantDirectly('ACCOUNT');

    expect(EmployeeRole::query()->where('role', 'ACCOUNT')->exists())->toBeTrue();
});

/**
 * The deliberate escape hatch, for seeders and the importer. It is entered on purpose and
 * carries a reason — which is the whole difference between this and a runningInConsole()
 * exemption that would open the door for every background process permanently.
 */
it('lets an explicit RestrictedRoleContext through, and demands a reason', function () {
    app(RestrictedRoleContext::class)->run(
        'Seeding the installation HR account.',
        fn () => grantDirectly('HR')
    );

    expect(EmployeeRole::query()->where('role', 'HR')->exists())->toBeTrue();

    expect(fn () => app(RestrictedRoleContext::class)->run('   ', fn () => null))
        ->toThrow(RuntimeException::class);
});

it('closes the context again afterwards, so the bypass cannot leak', function () {
    app(RestrictedRoleContext::class)->run('Seeding.', fn () => grantDirectly('HR'));

    expect(app(RestrictedRoleContext::class)->isActive())->toBeFalse();

    expect(fn () => grantDirectly('ACCOUNT'))->toThrow(RestrictedRoleGrantException::class);
});

/**
 * ⚠ The same door, one step sideways. Re-granting inserts a new row, so `creating` covers the
 * ordinary path — but an ->update() rewriting `role` on an existing row would reach ACCOUNT
 * without ever creating anything.
 */
it('refuses to rewrite an existing row into a restricted role', function () {
    $row = app(RestrictedRoleContext::class)->run('Fixture.', fn () => grantDirectly('SUPERVISOR'));

    $this->actingAs(anHrAccount());

    expect(fn () => $row->update(['role' => 'ACCOUNT']))
        ->toThrow(RestrictedRoleGrantException::class);

    expect($row->fresh()->role)->toBe('SUPERVISOR');
});
