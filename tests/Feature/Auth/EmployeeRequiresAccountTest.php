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

    foreach ([$this->ahs, $this->aim] as $company) {
        foreach ([
            'auth.password.min_length' => '6',
            'auth.throttle.tier_4.attempts' => '12',
            'auth.activation.validity_hours' => '48',
        ] as $key => $value) {
            PolicyConfiguration::create([
                'company_id' => $company->id,
                'key' => $key,
                'value' => $value,
                'effective_from' => now()->toDateString(),
            ]);
        }
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
 * ⚠ THE TRIPWIRE IS RETIRED — its work is done, and this is what replaced it.
 *
 * It asserted that no employee-creation Action existed, carrying the instruction to enforce
 * BR-A20 the day one did. `App\Actions\Employee\CreateEmployee` now exists, so the rule is
 * asserted directly instead of anticipated.
 *
 * ⚠ BR-A20 is structural rather than a rule somebody follows: the Action is the only way to
 * create an employee, and it creates the account in the SAME TRANSACTION. There is no order
 * of operations in which an employee exists without one.
 */
it('creates the account in the same transaction as the employee', function () {
    $result = app(App\Actions\Employee\CreateEmployee::class)->execute(
        Employee::factory()->raw(),
        '0123456789',
        $this->aim,
    );

    expect($result['user']->employee_id)->toBe($result['employee']->id)
        ->and($result['user']->phone_no)->toBe('0123456789')
        ->and(User::query()->count())->toBe(1)
        ->and(Employee::query()->count())->toBe(1);
});

it('leaves no employee behind when the account cannot be created', function () {
    // A username already taken by another account. The employee insert has already
    // succeeded by the time this fails, so only the transaction stops a person existing
    // with no way into the system.
    $existing = Employee::factory()->forCompany($this->aim)->create();
    User::factory()->forEmployee($existing)->create(['phone_no' => '0123456789']);

    expect(fn () => app(App\Actions\Employee\CreateEmployee::class)->execute(
        Employee::factory()->raw(),
        '0123456789',
        $this->aim,
    ))->toThrow(Illuminate\Database\QueryException::class);

    expect(Employee::query()->count())->toBe(1)      // only the pre-existing one
        ->and(User::query()->count())->toBe(1);
});
