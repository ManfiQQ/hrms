<?php

use App\Actions\Employee\ChangeEmployeeAssignment;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\Position;
use App\Models\User;

/**
 * POSITION, DEPARTMENT and LEVEL — the three `change_type` values §5.3 left unimplemented.
 *
 * ⚠ THE LEDGER ROW IS THE POINT, AND ITS ABSENCE IS INVISIBLE. An
 * `$employee->update(['department_id' => 7])` in a controller writes no history at all, and
 * nothing errors — the record simply has no answer to "when did this person move to
 * Logistics", exactly as the legacy system's flat fields had none.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->from = Department::factory()->shared()->create(['name' => 'HQ Marketing']);
    $this->to = Department::factory()->shared()->create(['name' => 'Logistics']);

    $this->employee = Employee::factory()
        ->forCompany($this->ahs)
        ->create(['department_id' => $this->from->id, 'level' => 'STAFF']);

    $this->actingAs(User::factory()->forEmployee(
        Employee::factory()->forCompany($this->ahs)->create(['department_id' => $this->from->id])
    )->create());

    $this->change = app(ChangeEmployeeAssignment::class);
});

it('moves the column and writes the ledger row in one transaction', function () {
    $this->change->execute($this->employee, 'DEPARTMENT', $this->to->id, '2026-03-01', 'Restructure');

    expect($this->employee->fresh()->department_id)->toBe($this->to->id);

    $row = EmployeeStatusHistory::query()->where('employee_id', $this->employee->id)->sole();

    expect($row->change_type)->toBe('DEPARTMENT')
        ->and($row->old_value)->toBe((string) $this->from->id)
        ->and($row->new_value)->toBe((string) $this->to->id)
        ->and($row->reason)->toBe('Restructure')
        ->and($row->changed_by)->toBe(auth()->id())
        ->and($row->effective_date->toDateString())->toBe('2026-03-01');
});

/**
 * ⚠ THE LABEL SNAPSHOT, AND WHY THE COLUMN EXISTS AT ALL. Storing `department_id = 7` alone
 * would need a join to render, and that join shows the department's name TODAY, not its name
 * THEN — so renaming a department would retroactively rewrite history, and a ledger that
 * changes retroactively is not a ledger.
 */
it('freezes the display text, so renaming a department later does not rewrite history', function () {
    $this->change->execute($this->employee, 'DEPARTMENT', $this->to->id, '2026-03-01');

    $this->to->update(['name' => 'Logistics & Warehousing']);

    $row = EmployeeStatusHistory::query()->where('employee_id', $this->employee->id)->sole();

    expect($row->old_label)->toBe('HQ Marketing')
        ->and($row->new_label)->toBe('Logistics');
});

it('resolves the position title as the label', function () {
    $old = Position::factory()->inDepartment($this->from)->titled('Executive')->create();
    $new = Position::factory()->inDepartment($this->from)->titled('Senior Executive')->create();

    $this->employee->update(['position_id' => $old->id]);

    $this->change->execute($this->employee, 'POSITION', $new->id, '2026-04-01');

    $row = EmployeeStatusHistory::query()->where('change_type', 'POSITION')->sole();

    expect($row->old_label)->toBe('Executive')
        ->and($row->new_label)->toBe('Senior Executive');
});

/**
 * LEVEL's label equals its value — redundant for an enum, and accepted: one uniform row shape
 * costs a few bytes and avoids per-type branching in every reader.
 */
it('records a level change with the enum as its own label', function () {
    $this->change->execute($this->employee, 'LEVEL', 'MANAGER', '2026-05-01');

    $row = EmployeeStatusHistory::query()->where('change_type', 'LEVEL')->sole();

    expect($this->employee->fresh()->level)->toBe('MANAGER')
        ->and($row->old_label)->toBe('STAFF')
        ->and($row->new_label)->toBe('MANAGER');
});

it('audits who changed it, alongside the ledger row rather than instead of it', function () {
    $this->change->execute($this->employee, 'LEVEL', 'MANAGER', '2026-05-01', 'Promotion');

    $audit = DB::table('audit_logs')->where('field', 'level')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->old_value)->toBe('STAFF')
        ->and($audit->new_value)->toBe('MANAGER')
        ->and($audit->user_id)->toBe(auth()->id());

    // Two different facts about one event — what the value WAS, and who moved it — not one
    // fact written twice (audit-trail.spec.md BR-AT5).
    expect(EmployeeStatusHistory::query()->count())->toBe(1);
});

it('refuses a no-op, because the ledger records only what happened', function () {
    expect(fn () => $this->change->execute($this->employee, 'LEVEL', 'STAFF', '2026-05-01'))
        ->toThrow(InvalidArgumentException::class);

    expect(EmployeeStatusHistory::query()->count())->toBe(0);
});

it('refuses a level outside the four', function () {
    expect(fn () => $this->change->execute($this->employee, 'LEVEL', 'ADMIN', '2026-05-01'))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * ⚠ STAFF_STATUS is not this Action's, and CORE_ROLE is not a change type at all.
 *
 * Status goes through ChangeEmployeeStatus, which also validates the BR-2 lifecycle and
 * freezes the account. Role changes write ONLY the pivot row — a service that appended a
 * ledger row for one would be wrong (`adr/0003` decision 8).
 */
it('refuses STAFF_STATUS and CORE_ROLE', function () {
    expect(fn () => $this->change->execute($this->employee, 'STAFF_STATUS', 'CONFIRMED', '2026-05-01'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->change->execute($this->employee, 'CORE_ROLE', 'HR', '2026-05-01'))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * ⚠ Moving departments is NOT a company transfer. `employees.company_id` is untouched and
 * nothing cascades; a transfer between group entities is TransferCompany, which does not
 * exist yet (§5.7, BR-17).
 */
it('leaves the employing company alone', function () {
    $this->change->execute($this->employee, 'DEPARTMENT', $this->to->id, '2026-03-01');

    expect($this->employee->fresh()->company_id)->toBe($this->ahs->id);
});
