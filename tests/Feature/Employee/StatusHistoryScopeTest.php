<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only employment ledger and its tenant-scope carve-out — adr/0003 decisions 7
 * and 8, conventions.md §2's second carve-out.
 *
 * ⚠ BOTH DIRECTIONS ARE ASSERTED, and that is the requirement rather than thoroughness.
 * Testing only that history survives a transfer turns a narrow carve-out into a blanket
 * bypass; testing only that reporting stays scoped leaves the silent-missing-rows failure in
 * place. Neither failure raises an exception — the first over-reports, the second makes an
 * employee look like a recent joiner — so only the pair catches them.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->tursenia = Company::factory()->subsidiary($this->ahs)->create(['code' => 'TURSENIA']);
});

function accountAtCompany(Company $company): User
{
    return User::factory()->forEmployee(
        Employee::factory()->forCompany($company)->create()
    )->create();
}

/**
 * ⚠ THE SILENT FAILURE THE CARVE-OUT EXISTS FOR.
 *
 * After a transfer, pre-transfer rows carry the OLD company id. Under the ordinary scope
 * they vanish, and the employee's history tab appears to begin on the transfer date — fewer
 * rows, no error, nothing to notice.
 */
it('keeps pre-transfer history visible through the employee relationship', function () {
    // An employee who used to work at AIM and now works at TURSENIA.
    $employee = Employee::factory()->forCompany($this->tursenia)->create();

    $beforeTransfer = EmployeeStatusHistory::factory()
        ->forEmployee($employee)
        ->frozenUnder($this->aim)          // frozen under the FORMER employer
        ->effectiveOn('2026-01-15')
        ->create();

    $afterTransfer = EmployeeStatusHistory::factory()
        ->forEmployee($employee)
        ->effectiveOn('2026-06-01')
        ->create();

    $this->actingAs(accountAtCompany($this->tursenia));

    $visible = $employee->statusHistory()->pluck('id');

    expect($visible)->toContain($beforeTransfer->id)   // the row the scope would have eaten
        ->toContain($afterTransfer->id)
        ->toHaveCount(2);
});

/**
 * ⚠ THE OTHER HALF. Without this, the carve-out is a hole: TURSENIA's report would count
 * promotions AIM made.
 */
it('keeps a direct reporting query fully tenant-scoped', function () {
    $employee = Employee::factory()->forCompany($this->tursenia)->create();

    $aimRow = EmployeeStatusHistory::factory()
        ->forEmployee($employee)
        ->frozenUnder($this->aim)
        ->create();

    $tursRow = EmployeeStatusHistory::factory()
        ->forEmployee($employee)
        ->create();

    $this->actingAs(accountAtCompany($this->tursenia));

    // "How many promotions did TURSENIA make this year" must stay TURSENIA's.
    $reported = EmployeeStatusHistory::query()->pluck('id');

    expect($reported)->toContain($tursRow->id)
        ->not->toContain($aimRow->id);
});

it('does not let the relationship release leak another employee\'s history', function () {
    // The release is justified by "if you may read this employee, you may read their
    // history" — so it must be bounded by the employee, not merely by the absence of a
    // scope. A release that returned everyone's rows would pass the first test above.
    $mine = Employee::factory()->forCompany($this->tursenia)->create();
    $theirs = Employee::factory()->forCompany($this->aim)->create();

    EmployeeStatusHistory::factory()->forEmployee($mine)->create();
    $theirRow = EmployeeStatusHistory::factory()->forEmployee($theirs)->create();

    $this->actingAs(accountAtCompany($this->tursenia));

    expect($mine->statusHistory()->pluck('id'))->not->toContain($theirRow->id)
        ->toHaveCount(1);
});

it('reads the history in effective-date order, not insertion order', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create();

    // Entered out of order, as back-dated changes are: HR types the promotion after the
    // confirmation, but it took effect before it.
    EmployeeStatusHistory::factory()->forEmployee($employee)->effectiveOn('2026-06-01')->create();
    EmployeeStatusHistory::factory()->forEmployee($employee)->effectiveOn('2026-01-15')->create();

    $this->actingAs(accountAtCompany($this->aim));

    expect($employee->statusHistory()->pluck('effective_date')->map->toDateString()->all())
        ->toBe(['2026-01-15', '2026-06-01']);
});

/** Append-only, enforced on the model as well as by the absence of an edit path. */
it('refuses to update a ledger row', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create();
    $row = EmployeeStatusHistory::factory()->forEmployee($employee)->create();

    // A correction is a new row. Mutability would defeat the point of the table: a ledger
    // that can be rewritten cannot answer "when did this employee move from Grade C to D".
    expect(fn () => $row->update(['new_value' => 'SUSPENDED']))->toThrow(RuntimeException::class);
});

it('refuses to delete a ledger row', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create();
    $row = EmployeeStatusHistory::factory()->forEmployee($employee)->create();

    expect(fn () => $row->delete())->toThrow(RuntimeException::class);
});

it('carries no updated_at, updated_by or deleted_at', function () {
    // A deliberate exception to conventions.md §3, and one a migration author is likely to
    // "fix" back for consistency's sake.
    foreach (['updated_at', 'updated_by', 'deleted_at'] as $column) {
        expect(Schema::hasColumn('employee_status_history', $column))->toBeFalse(
            "employee_status_history must not have {$column}: it is an append-only ledger ".
            '(adr/0003 decision 8, conventions.md §3).'
        );
    }

    expect(EmployeeStatusHistory::UPDATED_AT)->toBeNull();
});

/**
 * ⚠ CORE_ROLE must never appear. Role history lives in employee_roles, which records every
 * grant and revocation with its date, actor and reason — a second copy here would be two
 * records of one fact that can disagree.
 *
 * ⚠ FOUR BECAME FIVE ON 2026-08-13, AND EDITING THIS LINE IS THE GUARD WORKING RATHER THAN AN
 * OBSTACLE TO IT.
 *
 * The set is written out literally, not read from the constant it checks, so that a new value
 * CANNOT arrive without appearing in a diff and being argued for. `EMPLOYER` was argued for in
 * adr/0010: a company transfer is a ledger event, and employees.company_id was the only
 * mutable column on employees with no history — and the most statutorily loaded of them.
 *
 * This is the same shape as TenantScopeGuardTest going from two named exemptions to three when
 * EmployeeJobFunction earned one. A guard that quietly accepted whatever the constant said
 * would have nothing to catch.
 */
it('accepts exactly five change types and rejects CORE_ROLE at the database level', function () {
    expect(EmployeeStatusHistory::CHANGE_TYPES)
        ->toBe(['STAFF_STATUS', 'POSITION', 'DEPARTMENT', 'LEVEL', 'EMPLOYER']);

    $employee = Employee::factory()->forCompany($this->aim)->create();

    foreach (EmployeeStatusHistory::CHANGE_TYPES as $type) {
        EmployeeStatusHistory::factory()->forEmployee($employee)->ofType($type)->create();
    }

    expect(fn () => EmployeeStatusHistory::factory()
        ->forEmployee($employee)
        ->ofType('CORE_ROLE')
        ->create()
    )->toThrow(Illuminate\Database\QueryException::class);
});

/**
 * ⚠ The whole reason the labels exist. Storing only department_id = 7 needs a join to
 * render, and that join shows the department's name TODAY, not its name THEN.
 */
it('keeps the label frozen when the thing it named is renamed afterwards', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create();

    $row = EmployeeStatusHistory::factory()->forEmployee($employee)->create([
        'change_type' => 'DEPARTMENT',
        'old_value' => '7',
        'old_label' => 'Logistics',
        'new_value' => '9',
        'new_label' => 'HQ Marketing',
    ]);

    // The department is renamed a year later. History must not move with it — a ledger that
    // changes retroactively is not a ledger.
    expect($row->fresh()->new_label)->toBe('HQ Marketing');
});

it('keeps effective_date distinct from created_at', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create();

    // A promotion back-dated to last month: it applies from then, and was typed today.
    $row = EmployeeStatusHistory::factory()
        ->forEmployee($employee)
        ->effectiveOn(now()->subMonth()->toDateString())
        ->create();

    expect($row->effective_date->toDateString())->toBe(now()->subMonth()->toDateString())
        ->and($row->created_at->toDateString())->toBe(now()->toDateString());
});

it('freezes company_id rather than following the employee', function () {
    // The invariant the carve-out rests on: an event row records the employer AT THE TIME,
    // and a transfer must not rewrite it. A payslip issued by AIM must not become
    // TURSENIA's because the person moved afterwards — that is falsification, not an update.
    $employee = Employee::factory()->forCompany($this->tursenia)->create();

    $frozen = EmployeeStatusHistory::factory()
        ->forEmployee($employee)
        ->frozenUnder($this->aim)
        ->create();

    expect($frozen->company_id)->toBe($this->aim->id)
        ->and($frozen->company_id)->not->toBe($employee->company_id);
});
