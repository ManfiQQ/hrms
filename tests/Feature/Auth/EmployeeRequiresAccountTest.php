<?php

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PolicyConfiguration;
use App\Models\User;
use App\Services\Auth\AuthenticationService;
use Illuminate\Support\Facades\Schema;

/**
 * adr/0006 follow-up item 6 — BR-A20's dependency, now that the username has moved.
 *
 * ⚠ WHAT THIS FILE HONESTLY COVERS, AND WHAT IT DOES NOT.
 *
 * BR-A20 requires the account to be created in the same transaction as the employee. That is
 * enforced by Employee Master's creation Action, which **does not exist** — `app/Actions` is
 * empty and `AuditedFields` still declares itself intentionally empty for the same reason.
 * So nothing in the codebase can currently prevent an employee being created without an
 * account, and a test claiming otherwise would be asserting a rule with no implementation
 * behind it.
 *
 * What is asserted here is the CONSEQUENCE, which is real today: with `phone_no` on `users`,
 * an employee without an account has no username and can never log in. That is the fact
 * Employee Master must honour, and the last test pins it so the day the Action lands, the
 * requirement is already written down and failing if it is skipped.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

    foreach (['auth.password.min_length' => '6', 'auth.throttle.tier_4.attempts' => '12'] as $key => $value) {
        PolicyConfiguration::create([
            'company_id' => $this->ahs->id,
            'key' => $key,
            'value' => $value,
            'effective_from' => now()->toDateString(),
        ]);
    }
});

/** The structural half: an account cannot exist without a username. */
it('will not create an account without a login username', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create();

    expect(fn () => User::factory()->forEmployee($employee)->create(['phone_no' => null]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('keeps the username NOT NULL at the database level, not merely by convention', function () {
    $column = collect(Schema::getColumns('users'))->firstWhere('name', 'phone_no');

    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeFalse(
            'phone_no must be NOT NULL: an account without one cannot be logged into by '.
            'anybody, which is the defect adr/0006 closed.'
        );
});

/**
 * ⚠ The consequence Employee Master must honour.
 *
 * An employee with no account is not a data error the database can catch — it is a person
 * with no way into the system. BR-A20 exists to make it impossible, and until its Action is
 * written this is what the gap looks like.
 */
it('leaves an employee with no account unable to authenticate at all', function () {
    Employee::factory()->forCompany($this->aim)->create();

    // There is no username to try, because the employee holds none. Any number belongs to
    // nobody, which is the same answer an attacker gets — and the same one this employee
    // would get on their first day if HR created them without an account.
    expect(fn () => app(AuthenticationService::class)->attempt('0123456789', 'anything'))
        ->toThrow(InvalidCredentialsException::class);

    expect(User::query()->count())->toBe(0);
});

/**
 * ⚠ Pins the gap so it cannot be forgotten. When the employee-CREATION Action lands, this
 * test fails and points at the requirement: BR-A20 must be enforced there, in the same
 * transaction, and this file should then assert it directly.
 *
 * ⚠ RETARGETED 2026-08-12. It used to assert that `app/Actions/Employee` did not exist —
 * which fired the moment ChangeEmployeeStatus landed in that directory, for a change that
 * has nothing to do with BR-A20. A guard pointed at the wrong subject fires on unrelated
 * work, and a guard that cries wolf gets deleted rather than fixed.
 *
 * ⚠ Its weak point is now the class NAME, and that is worth stating rather than hiding: an
 * employee-creation Action called something else slips past. `employee-master.spec.md` §5.1
 * names `App\Actions\Employee\*` as the home for these, and creation is the obvious one to
 * call CreateEmployee — but if it arrives as `RegisterEmployee` or `ProvisionEmployee`, this
 * guard is silent. It narrows the window; it does not close it.
 */
it('has no employee-creation Action yet, so BR-A20 is not structurally enforced', function () {
    expect(class_exists(App\Actions\Employee\CreateEmployee::class))->toBeFalse(
        'An employee-creation Action now exists. BR-A20 must be enforced in it — the account '.
        'created in the same transaction as the employee — and this test replaced with one '.
        'asserting that an employee cannot be created without an account (adr/0006 item 6).'
    );
});
