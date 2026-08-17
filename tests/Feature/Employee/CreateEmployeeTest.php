<?php

use App\Actions\Employee\CreateEmployee;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PolicyConfiguration;
use App\Models\Sequence;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * BR-A20 — the account is created in the same transaction as the employee.
 *
 * ⚠ Not a convenience. Every employee must verify their own attendance data, and payroll is
 * BLOCKED when attendance is incomplete — so an employee without an account is a person who
 * cannot verify, and a payroll run that cannot proceed. Since adr/0006 it is structural too:
 * the username lives on the account, so an employee without one has no way into the system
 * at all.
 */
beforeEach(function () {
    // ⚠ actingAs, not AuthorshipContext: registration is an act BY somebody. HR performs it in
    // production, the audit row records who, and adr/0009 attributes the rows to them. A
    // context here would model a seeder, which this is not.
    $this->actingAs(User::factory()->masterAdmin()->create());

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

    $this->action = app(CreateEmployee::class);
});

function createEmployeeAt(Company $company, string $phone = '0123456789', array $overrides = []): array
{
    return test()->action->execute(
        Employee::factory()->raw($overrides),
        $phone,
        $company,
    );
}

it('creates the employee, the number, the account and the token together', function () {
    $result = createEmployeeAt($this->aim);

    expect($result['employee']->company_id)->toBe($this->aim->id)
        ->and($result['employee']->employee_no)->toBe('AHS-0001')
        ->and($result['user']->employee_id)->toBe($result['employee']->id)
        ->and($result['user']->phone_no)->toBe('0123456789')
        ->and($result['user']->system_access)->toBe('STANDARD')
        ->and($result['user']->must_change_password)->toBeTrue()
        ->and($result['activationToken'])->toHaveLength(64);
});

/**
 * ⚠ ALWAYS THE AHS PREFIX, regardless of the employing subsidiary. An AIM employee is
 * AHS-0042, not AIM-0042. Counterintuitive enough to be "corrected" by mistake, so it is
 * asserted: the unique index on employee_no is group-wide and a per-company counter would
 * collide against it (BR-13, §10 decision 1).
 */
it('numbers every company from one group-wide sequence', function () {
    $first = createEmployeeAt($this->aim, '0123456789');
    $second = createEmployeeAt($this->ahs, '0123456780');
    $third = createEmployeeAt($this->aim, '0123456781');

    expect([$first, $second, $third])
        ->each->toHaveKey('employee');

    expect($first['employee']->employee_no)->toBe('AHS-0001')
        ->and($second['employee']->employee_no)->toBe('AHS-0002')   // different company
        ->and($third['employee']->employee_no)->toBe('AHS-0003');
});

it('discards any employee_no the caller supplies', function () {
    // The locked sequence is the only permitted source. A caller-supplied number is the
    // MAX() + 1 collision arriving through the front door (BR-13).
    $result = createEmployeeAt($this->aim, '0123456789', ['employee_no' => 'AHS-9999']);

    expect($result['employee']->employee_no)->toBe('AHS-0001');
});

/**
 * ⚠ THE HALF-COMPLETED REGISTRATION THIS TRANSACTION EXISTS TO PREVENT.
 *
 * An employee row with no account is a person who cannot log in. A burned employee_no with
 * no employee is a gap in a sequence that must never rewind. Both are invisible until
 * somebody tries to use them.
 */
it('leaves neither an employee nor a burned number when the account fails', function () {
    // Take the username first, so the account insert fails after the employee has been
    // written and the number claimed.
    $existing = Employee::factory()->forCompany($this->aim)->create();
    User::factory()->forEmployee($existing)->create(['phone_no' => '0123456789']);

    expect(fn () => createEmployeeAt($this->aim, '0123456789'))
        ->toThrow(Illuminate\Database\QueryException::class);

    expect(Employee::query()->count())->toBe(1)          // only the pre-existing one
        ->and(User::query()->whereNotNull('employee_id')->count())->toBe(1);

    // ⚠ And the number was not burned. The next real registration gets AHS-0001, not
    // AHS-0002 — a rolled-back transaction must leave no gap.
    $next = createEmployeeAt($this->aim, '0119999999');

    expect($next['employee']->employee_no)->toBe('AHS-0001');
});

it('refuses a phone number BR-A1 would not accept, before writing anything', function (string $bad) {
    expect(fn () => createEmployeeAt($this->aim, $bad))->toThrow(InvalidArgumentException::class);

    expect(Employee::query()->count())->toBe(0)
        ->and(Sequence::query()->count())->toBe(0);   // the sequence was never touched
})->with(['12345', 'not-a-number', '']);

it('normalises the username so every format reaches the account', function () {
    $result = createEmployeeAt($this->aim, '+6012-345 6789');

    // BR-A1's single normaliser. A number stored as typed would create an account reachable
    // only by retyping the exact punctuation.
    expect($result['user']->phone_no)->toBe('0123456789');
});

/**
 * ⚠ NO USABLE PASSWORD IS CREATED, and that is the decision. The IC number was proposed as a
 * first password and rejected: it is not a secret, cannot be changed, and would open a
 * window until first login in which anyone knowing a phone number and an IC could enter as
 * that person — with the audit log showing the employee themselves (adr/0004 decision 7).
 */
it('creates the account with no password anybody could use', function () {
    $result = createEmployeeAt($this->aim, '0123456789', ['full_name' => 'Aminah binti Yusof']);

    $user = $result['user'];

    // Nothing derived from the person. Every value somebody might guess must fail.
    foreach (['0123456789', 'Aminah binti Yusof', 'AHS-0001', 'password', ''] as $guess) {
        expect(Hash::check($guess, $user->password))->toBeFalse();
    }

    // And the account is gated until the QR is redeemed.
    expect($user->must_change_password)->toBeTrue();
});

it('issues a token valid for the configured window, unused and undownloaded', function () {
    $result = createEmployeeAt($this->aim);
    $user = $result['user']->fresh();

    expect($user->activation_token)->toBe($result['activationToken'])
        ->and($user->activation_expires_at->diffInHours(now()->addHours(48), absolute: true))->toBeLessThan(1)
        // Null means HR has not fetched the image; null means not yet redeemed (BR-A22).
        ->and($user->activation_downloaded_at)->toBeNull()
        ->and($user->activation_used_at)->toBeNull();
});

it('audits the registration against the employee', function () {
    $result = createEmployeeAt($this->aim);

    $audit = AuditLog::query()->where('action', 'employee.created')->sole();

    expect($audit->field)->toBe('employee_no')
        ->and($audit->old_value)->toBeNull()
        ->and($audit->new_value)->toBe('AHS-0001')
        ->and($audit->auditable_id)->toBe($result['employee']->id);
});

/**
 * ⚠ `superseded_at` ON BOTH MODELS SINCE adr/0015, and the second entry is not redundant. On
 * `Employee` the audit row records that a historical record released its identity numbers; on
 * `User` it records that an account released a LOGIN USERNAME — the security-relevant half, since
 * a wrongly-set value there lets two live accounts share one. An audit trail showing only the
 * employee half would name the event without naming the credential it moved.
 *
 * Both pairs must also appear in App\Support\Audit\AuditedFields — AuditAuthorshipTest fails in
 * both directions, so an entry here with no registry entry is caught, and vice versa.
 */
it('declares every field it audits', function () {
    expect(CreateEmployee::AUDITS)->toBe([
        Employee::class => ['employee_no', 'superseded_at'],
        User::class => ['superseded_at'],
    ]);
});
