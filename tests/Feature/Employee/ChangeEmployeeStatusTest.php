<?php

use App\Actions\Employee\ChangeEmployeeStatus;
use App\Events\Auth\AccountFrozen;
use App\Exceptions\Employee\InvalidStatusTransitionException;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\EmployeeStatusHistory;
use App\Models\PolicyConfiguration;
use App\Models\User;
use App\Services\Auth\AccountExpiry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The one way `staff_status` changes — employee-master.spec.md §5.3, BR-2, and
 * auth-rbac.spec.md BR-A15.
 *
 * ⚠ THE TRANSACTION IS THE DESIGN, not an implementation detail. The ledger row, the role
 * revocations, the session kill and the audit rows either all land or none do — so the tests
 * that matter most here are the ones that fail the Action halfway and assert nothing
 * survived.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

    foreach ([$this->ahs, $this->aim] as $company) {
        foreach (['auth.throttle.tier_4.attempts' => '12', 'auth.account.expiry_days' => '10'] as $key => $value) {
            PolicyConfiguration::create([
                'company_id' => $company->id,
                'key' => $key,
                'value' => $value,
                'effective_from' => now()->toDateString(),
            ]);
        }
    }

    $this->hr = User::factory()->forEmployee(
        Employee::factory()->forCompany($this->ahs)->create()
    )->create();

    $this->actingAs($this->hr);

    $this->action = app(ChangeEmployeeStatus::class);
});

function employeeWithStatus(string $status): Employee
{
    return Employee::factory()->forCompany(test()->aim)->create(['staff_status' => $status]);
}

/** §5.3 — the caller cannot forget the ledger row, because the caller does not write it. */
it('writes the ledger row itself, in the same transaction as the change', function () {
    $employee = employeeWithStatus('PROBATION');

    $this->action->execute($employee, 'CONFIRMED', '2026-03-01', 'Probation passed');

    $row = EmployeeStatusHistory::query()->sole();

    expect($employee->fresh()->staff_status)->toBe('CONFIRMED')
        ->and($row->change_type)->toBe('STAFF_STATUS')
        ->and($row->old_value)->toBe('PROBATION')
        ->and($row->new_value)->toBe('CONFIRMED')
        ->and($row->effective_date->toDateString())->toBe('2026-03-01')
        ->and($row->reason)->toBe('Probation passed')
        ->and($row->changed_by)->toBe($this->hr->id);
});

/**
 * ⚠ effective_date is the date the change APPLIES, not today. BR-A17's expiry counts from
 * it, so an Action that stamped `now()` would hand a late-entered resignation extra days of
 * account access.
 */
it('records the effective date it was given, not today', function () {
    $employee = employeeWithStatus('CONFIRMED');

    $this->action->execute($employee, 'RESIGNED', now()->subDays(21)->toDateString());

    $row = EmployeeStatusHistory::query()->where('change_type', 'STAFF_STATUS')->sole();

    expect($row->effective_date->toDateString())->toBe(now()->subDays(21)->toDateString())
        ->and($row->created_at->toDateString())->toBe(now()->toDateString());
});

it('audits who changed it and why, without mirroring the ledger', function () {
    $employee = employeeWithStatus('CONFIRMED');

    $this->action->execute($employee, 'SUSPENDED', '2026-04-01', 'Disciplinary hold');

    $audit = AuditLog::query()->where('action', 'employee.status_change')->sole();

    // Two different facts about one event: the ledger says what the status WAS on a date,
    // the audit says who changed it. Not the duplication adr/0003 decision 8 forbids.
    expect($audit->field)->toBe('staff_status')
        ->and($audit->old_value)->toBe('CONFIRMED')
        ->and($audit->new_value)->toBe('SUSPENDED')
        ->and($audit->reason)->toBe('Disciplinary hold')
        ->and($audit->user_id)->toBe($this->hr->id)
        ->and($audit->auditable_id)->toBe($employee->id);
});

/** BR-2, enforced in the service layer — not in a FormRequest an importer would bypass. */
it('permits the transitions BR-2 allows', function (string $from, string $to) {
    $employee = employeeWithStatus($from);

    $this->action->execute($employee, $to, '2026-03-01');

    expect($employee->fresh()->staff_status)->toBe($to);
})->with([
    ['PROBATION', 'CONFIRMED'],
    ['PROBATION', 'ACTIVE'],
    ['ACTIVE', 'CONFIRMED'],
    ['CONFIRMED', 'SUSPENDED'],
    ['SUSPENDED', 'ACTIVE'],
    ['CONFIRMED', 'RESIGNED'],
    ['CONFIRMED', 'TERMINATED'],
]);

it('refuses a transition BR-2 does not allow, and writes nothing', function () {
    $employee = employeeWithStatus('PROBATION');

    // Backwards down the lifecycle.
    expect(fn () => $this->action->execute($employee, 'PROBATION', '2026-03-01'))
        ->toThrow(InvalidStatusTransitionException::class);

    expect($employee->fresh()->staff_status)->toBe('PROBATION')
        ->and(EmployeeStatusHistory::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

/**
 * ⚠ Terminal means terminal, for everyone. Reinstatement is a NEW record with a new
 * employee_no linked by previous_employee_id — never a status flip back (BR-2, BR-A18).
 */
it('refuses to bring a terminal status back to life', function (string $to) {
    $employee = employeeWithStatus('RESIGNED');

    expect(fn () => $this->action->execute($employee, $to, '2026-03-01'))
        ->toThrow(InvalidStatusTransitionException::class, 'terminal');
})->with(['ACTIVE', 'CONFIRMED', 'PROBATION', 'SUSPENDED', 'TERMINATED']);

/** BR-A15 — freeze in the same transaction. */
it('revokes every role when the status becomes terminal', function (string $terminal) {
    $employee = employeeWithStatus('CONFIRMED');

    EmployeeRole::factory()->role('MANAGER')->forCompany($this->aim)->create(['employee_id' => $employee->id]);
    EmployeeRole::factory()->role('SUPERVISOR')->forCompany($this->ahs)->create(['employee_id' => $employee->id]);

    expect($employee->roles()->count())->toBe(2);

    $this->action->execute($employee, $terminal, '2026-06-30');

    // Rows are never deleted — revocation is revoked_date alone, and the history stays
    // readable (adr/0003 decision 1).
    expect($employee->roles()->count())->toBe(0)
        ->and(EmployeeRole::withRevoked()->where('employee_id', $employee->id)->count())->toBe(2);
})->with(['RESIGNED', 'TERMINATED']);

/**
 * ⚠ THE ONE PLACE THE TWO TERMINAL STATUSES DIVERGE, and both halves are asserted.
 *
 * Termination may follow serious misconduct, and waiting for the person's next request —
 * which may never come while a screen sits open — leaves access in their hands. A resigning
 * employee is typically still working, and cutting their session mid-task achieves nothing,
 * since they may log back in as a frozen account regardless (adr/0004 decision 5).
 */
it('kills sessions for TERMINATED and leaves them for RESIGNED', function (string $status, int $remaining) {
    $employee = employeeWithStatus('CONFIRMED');
    $account = User::factory()->forEmployee($employee)->create();

    DB::table('sessions')->insert([
        'id' => 'sess-'.$status, 'user_id' => $account->id, 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);

    $this->action->execute($employee, $status, '2026-06-30');

    expect(DB::table('sessions')->where('user_id', $account->id)->count())->toBe($remaining);
})->with([
    'TERMINATED ends them now' => ['TERMINATED', 0],
    'RESIGNED leaves them' => ['RESIGNED', 1],
]);

it('audits the session termination, because access was taken away without their involvement', function () {
    $employee = employeeWithStatus('CONFIRMED');
    $account = User::factory()->forEmployee($employee)->create();

    DB::table('sessions')->insert([
        'id' => 'sess-x', 'user_id' => $account->id, 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);

    $this->action->execute($employee, 'TERMINATED', '2026-06-30');

    expect(AuditLog::query()->where('action', 'employee.sessions_terminated')->count())->toBe(1);
});

it('emits the freeze event for the Approval Engine to pick up later', function (string $terminal) {
    Event::fake([AccountFrozen::class]);

    $employee = employeeWithStatus('CONFIRMED');

    $this->action->execute($employee, $terminal, '2026-06-30');

    // Nothing listens yet — the Approval Engine has no spec, so the routing is not written.
    // The trigger is this module's; the routing is not (BR-A16).
    Event::assertDispatched(AccountFrozen::class, fn (AccountFrozen $e) => $e->employee->is($employee) && $e->status === $terminal);
})->with(['RESIGNED', 'TERMINATED']);

it('does not freeze a non-terminal change', function () {
    Event::fake([AccountFrozen::class]);

    $employee = employeeWithStatus('PROBATION');
    EmployeeRole::factory()->role('SUPERVISOR')->forCompany($this->aim)->create(['employee_id' => $employee->id]);

    $this->action->execute($employee, 'CONFIRMED', '2026-03-01');

    expect($employee->roles()->count())->toBe(1);
    Event::assertNotDispatched(AccountFrozen::class);
});


/**
 * ⚠ The reason this Action exists rather than an ->update() in a controller: BR-A17 counts
 * from the ledger row, so a terminal status written without one never expires.
 */
it('leaves the account able to expire, because the ledger row is always there', function () {
    $employee = employeeWithStatus('CONFIRMED');
    $account = User::factory()->forEmployee($employee)->create();

    $this->action->execute($employee, 'RESIGNED', now()->subDays(11)->toDateString());

    expect(app(AccountExpiry::class)->hasExpired($account->fresh()))->toBeTrue();
});

it('declares every field it audits, and the registry agrees', function () {
    // BR-AT13's two-way check lives in AuditAuthorshipTest; this asserts the pair is present
    // here so a reader of the Action sees the contract without chasing it.
    expect(ChangeEmployeeStatus::AUDITS)->toBe([Employee::class => ['staff_status']]);
});
