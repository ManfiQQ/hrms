<?php

use App\Actions\Employee\GrantRole;
use App\Actions\Employee\RevokeRole;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\User;

/**
 * GrantRole and RevokeRole — `employee-master.spec.md` §5.6, `adr/0003` decision 1.
 *
 * ⚠ EVERY FAILURE HERE RETURNS A WRONG ANSWER RATHER THAN AN ERROR. A revocation that
 * deletes leaves no history; a re-grant that clears `revoked_date` erases the middle of a
 * cycle while appearing to restore it; a duplicate live row means withdrawing one leaves the
 * other standing — authority that survives its own withdrawal, with nothing to notice.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->dept = Department::factory()->shared()->create(['name' => 'HQ']);

    $this->subject = Employee::factory()
        ->forCompany($this->ahs)
        ->create(['department_id' => $this->dept->id]);

    // MANAGER and SUPERVISOR are unrestricted, so these run as an ordinary HR — the actor
    // BR-16 permits for routine appointments.
    $this->actingAs(User::factory()->forEmployee(
        Employee::factory()->forCompany($this->ahs)->create(['department_id' => $this->dept->id])
    )->create());

    $this->grant = app(GrantRole::class);
    $this->revoke = app(RevokeRole::class);
});

it('records who granted it and the date it applies from', function () {
    $row = $this->grant->execute($this->subject, 'MANAGER', $this->aim, '2026-01-15');

    expect($row->role)->toBe('MANAGER')
        ->and($row->company_id)->toBe($this->aim->id)
        ->and($row->assigned_by)->toBe(auth()->id())
        ->and($row->revoked_date)->toBeNull();

    // ⚠ effective_date is distinct from created_at: a promotion is typically effective
    // before HR gets to enter it, and the ledger records both (§5.6).
    expect($row->effective_date->toDateString())->toBe('2026-01-15')
        ->and($row->created_at->toDateString())->toBe(now()->toDateString());
});

/**
 * ⚠ THE MOST DANGEROUS OMISSION IN THE MODULE, asserted through the model's default scope
 * rather than by hand-writing the condition. A query missing `revoked_date IS NULL` returns
 * revoked authority as live and NOTHING FAILS — the request is simply approved by someone who
 * should no longer be able to.
 */
it('revokes by setting a date, never by deleting the row', function () {
    $this->grant->execute($this->subject, 'MANAGER', $this->aim, '2026-01-15');

    $revoked = $this->revoke->execute($this->subject, 'MANAGER', $this->aim, 'Reassigned');

    expect($revoked->revoked_date)->not->toBeNull()
        ->and($revoked->revoked_by)->toBe(auth()->id())
        ->and($revoked->revoke_reason)->toBe('Reassigned');

    // Gone from ordinary queries — and still on disk, which is the whole point.
    expect(EmployeeRole::query()->where('employee_id', $this->subject->id)->exists())->toBeFalse()
        ->and(EmployeeRole::withRevoked()->where('employee_id', $this->subject->id)->count())->toBe(1);
});

/**
 * §5.6 — re-granting inserts a NEW row, preserving the full cycle (held Jan–Aug, revoked Aug,
 * re-granted November) that a boolean toggle cannot express. A service that cleared
 * `revoked_date` on the original row would erase the middle of that history while appearing
 * to restore it.
 */
it('re-grants as a second row, not a resurrection of the first', function () {
    $this->grant->execute($this->subject, 'MANAGER', $this->aim, '2026-01-15');
    $this->revoke->execute($this->subject, 'MANAGER', $this->aim);
    $this->grant->execute($this->subject, 'MANAGER', $this->aim, '2026-11-01');

    $all = EmployeeRole::withRevoked()->where('employee_id', $this->subject->id)->get();

    expect($all)->toHaveCount(2)
        ->and($all->whereNull('revoked_date'))->toHaveCount(1)
        ->and($all->whereNotNull('revoked_date'))->toHaveCount(1);
});

it('refuses a second live row for the same employee, company and role', function () {
    $this->grant->execute($this->subject, 'MANAGER', $this->aim, '2026-01-15');

    expect(fn () => $this->grant->execute($this->subject, 'MANAGER', $this->aim, '2026-02-01'))
        ->toThrow(InvalidArgumentException::class);

    expect(EmployeeRole::query()->where('employee_id', $this->subject->id)->count())->toBe(1);
});

/**
 * ⚠ Authority is per company, so the same role at a different company is a different grant
 * and must NOT be refused as a duplicate. Asserting only the refusal above would pass against
 * an implementation that ignored company entirely.
 */
it('allows the same role at a different company', function () {
    $this->grant->execute($this->subject, 'MANAGER', $this->aim, '2026-01-15');
    $this->grant->execute($this->subject, 'MANAGER', $this->ahs, '2026-01-15');

    expect(EmployeeRole::query()->where('employee_id', $this->subject->id)->count())->toBe(2);
});

it('refuses a value that is not one of the six authority roles', function () {
    // ⚠ STAFF especially: ordinary staff hold NO row at all, and defining a value for the
    // absence of authority would be a second way to express one state (adr/0003 decision 1).
    expect(fn () => $this->grant->execute($this->subject, 'STAFF', $this->aim, '2026-01-15'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->grant->execute($this->subject, 'MASTER_ADMIN', $this->aim, '2026-01-15'))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to revoke a role that is not currently held', function () {
    expect(fn () => $this->revoke->execute($this->subject, 'MANAGER', $this->aim))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * ⚠ Neither Action writes to audit_logs, and that is the decision rather than an omission.
 * The employee_roles row IS the record — assigned_by, effective_date, revoked_by,
 * revoke_reason. Mirroring it would be two records of one fact, which is why CORE_ROLE was
 * kept out of employee_status_history.change_type (adr/0003 decision 8).
 */
it('writes no audit row and no ledger row, because the pivot is the record', function () {
    $this->grant->execute($this->subject, 'MANAGER', $this->aim, '2026-01-15');
    $this->revoke->execute($this->subject, 'MANAGER', $this->aim);

    expect(DB::table('audit_logs')->count())->toBe(0)
        ->and(DB::table('employee_status_history')->count())->toBe(0);
});
