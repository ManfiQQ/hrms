<?php

use App\Actions\Employee\TransferCompany;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducationHistory;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeFamilyMember;
use App\Models\EmployeeJobFunction;
use App\Models\EmployeeRole;
use App\Models\EmployeeStatusHistory;
use App\Models\JobFunction;
use App\Models\User;

/**
 * `TransferCompany` — `employee-master.spec.md` §5.7, BR-17, `adr/0003` decision 7,
 * `adr/0010`.
 *
 * ⚠ THE THREE CASCADE CATEGORIES ARE ASSERTED SEPARATELY, AND THAT IS THE REQUIREMENT RATHER
 * THAN THOROUGHNESS. A suite that asserted "it moved" three times would pass while being wrong
 * two ways round: descriptive rows must FOLLOW, event rows must FREEZE, and company-reference
 * rows must be UNTOUCHED. One test covering all three at once cannot tell those apart, and
 * every one of the failures is silent — a cascade that goes too far corrupts data that looks
 * fine at insert time and only breaks months later.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->tursenia = Company::factory()->subsidiary($this->ahs)->create(['code' => 'TURSENIA']);

    $this->fromDept = Department::factory()->shared()->create(['name' => 'HQ Marketing']);
    $this->toDept = Department::factory()->shared()->create(['name' => 'Logistics']);

    // HR performs a transfer in production, and the audit row records who. adr/0009 also
    // attributes every write to them.
    $this->actor = User::factory()->masterAdmin()->create();
    $this->actingAs($this->actor);

    $this->employee = Employee::factory()
        ->forCompany($this->aim)
        ->create(['department_id' => $this->fromDept->id]);

    $this->transfer = app(TransferCompany::class);
});

function xferTo(?Department $department = null, ?string $reason = null): array
{
    return test()->transfer->execute(
        test()->employee,
        test()->tursenia,
        ($department ?? test()->toDept)->id,
        '2026-04-01',
        $reason,
    );
}

it('moves the employee and keeps the employee_no with the person', function () {
    $number = $this->employee->employee_no;

    xferTo();

    expect($this->employee->fresh()->company_id)->toBe($this->tursenia->id)
        ->and($this->employee->fresh()->department_id)->toBe($this->toDept->id)
        ->and($this->employee->fresh()->employee_no)->toBe($number);
});

// ═══════════════════════════════════════════════════════════════════════════════
// CATEGORY 1 — DESCRIPTIVE: company_id FOLLOWS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * The tenant marker is denormalized from the employee, so it moves with them. Left behind, the
 * rows fall outside the new employer's scope and the employee's Family, Education, Employment
 * History and Documents tabs go blank — fewer rows, no error.
 */
it('cascades company_id to all four descriptive child tables', function () {
    $family = EmployeeFamilyMember::factory()->forEmployee($this->employee)->create();
    $education = EmployeeEducationHistory::factory()->forEmployee($this->employee)->create();
    $employment = EmployeeEmploymentHistory::factory()->forEmployee($this->employee)->create();
    $document = EmployeeDocument::factory()->forEmployee($this->employee)->create();

    foreach ([$family, $education, $employment, $document] as $row) {
        expect($row->company_id)->toBe($this->aim->id);
    }

    xferTo();

    // Scope lifted on read too, so the assertion sees the row wherever it landed rather than
    // only where the reader may look — otherwise a row left behind would look identical to a
    // row correctly moved.
    expect(EmployeeFamilyMember::withoutGlobalScopes()->find($family->id)->company_id)->toBe($this->tursenia->id)
        ->and(EmployeeEducationHistory::withoutGlobalScopes()->find($education->id)->company_id)->toBe($this->tursenia->id)
        ->and(EmployeeEmploymentHistory::withoutGlobalScopes()->find($employment->id)->company_id)->toBe($this->tursenia->id)
        ->and(EmployeeDocument::withoutGlobalScopes()->find($document->id)->company_id)->toBe($this->tursenia->id);
});

/**
 * ⚠ ITS OWN TEST, NOT A DETAIL OF THE ONE ABOVE, because the failure is silent for years.
 *
 * An archived document is still that person's. One left carrying the old company_id sits
 * harmlessly until somebody restores it — and then it comes back into the WRONG TENANT, long
 * after anybody would connect it to a transfer.
 */
it('moves soft-deleted child rows too', function () {
    $archived = EmployeeDocument::factory()->forEmployee($this->employee)->create();
    $archived->delete();

    expect($archived->fresh()->trashed())->toBeTrue();

    xferTo();

    $moved = EmployeeDocument::withoutGlobalScopes()->withTrashed()->find($archived->id);

    expect($moved->company_id)->toBe($this->tursenia->id)
        ->and($moved->trashed())->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════════════════════
// CATEGORY 2 — EVENT: frozen forever
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * A promotion recorded under AIM must not become TURSENIA's because the person moved
 * afterwards. That is not an update, it is falsification (`adr/0003` decision 7).
 */
it('freezes pre-transfer ledger rows at the old employer', function () {
    $before = EmployeeStatusHistory::factory()
        ->forEmployee($this->employee)
        ->ofType('LEVEL')
        ->create();

    expect($before->company_id)->toBe($this->aim->id);

    xferTo();

    expect(EmployeeStatusHistory::withoutGlobalScopes()->find($before->id)->company_id)
        ->toBe($this->aim->id);
});

/**
 * ⚠ `adr/0010` decision 3 — the arrival row belongs to the company being arrived at.
 *
 * Frozen to the old employer instead, the new company's reporting would contain no record of
 * the arrival at all and the person would appear from nowhere: the silent-missing-rows failure
 * `adr/0002` exists to prevent, reproduced in reporting.
 */
it('writes an EMPLOYER row frozen to the NEW company', function () {
    xferTo();

    $row = EmployeeStatusHistory::withoutGlobalScopes()
        ->where('change_type', 'EMPLOYER')->sole();

    expect($row->company_id)->toBe($this->tursenia->id)
        ->and($row->old_value)->toBe((string) $this->aim->id)
        ->and($row->new_value)->toBe((string) $this->tursenia->id)
        ->and($row->old_label)->toBe('AIM')
        ->and($row->new_label)->toBe('TURSENIA')
        ->and($row->effective_date->toDateString())->toBe('2026-04-01')
        ->and($row->changed_by)->toBe($this->actor->id);
});

/** §5.3 forces this row independently of adr/0010, and it freezes the same way. */
it('writes a DEPARTMENT row frozen to the NEW company, with the names of the day', function () {
    xferTo();

    $row = EmployeeStatusHistory::withoutGlobalScopes()
        ->where('change_type', 'DEPARTMENT')->sole();

    expect($row->company_id)->toBe($this->tursenia->id)
        ->and($row->old_label)->toBe('HQ Marketing')
        ->and($row->new_label)->toBe('Logistics');
});

/**
 * A shared department kept across a transfer is legitimate (`adr/0002`), and a no-op is not a
 * change: writing a row for one would put an event in the ledger that never happened.
 */
it('writes no DEPARTMENT row when the department is unchanged', function () {
    xferTo($this->fromDept);

    expect(EmployeeStatusHistory::withoutGlobalScopes()->where('change_type', 'DEPARTMENT')->count())->toBe(0)
        ->and(EmployeeStatusHistory::withoutGlobalScopes()->where('change_type', 'EMPLOYER')->count())->toBe(1);
});

// ═══════════════════════════════════════════════════════════════════════════════
// CATEGORY 3 — COMPANY-REFERENCE: untouched
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * ⚠ Cascading these would not merely misplace the data, it would CORRUPT it. A Manager role at
 * AIM is still a Manager role at AIM after the person's payroll moves elsewhere.
 */
it('leaves employee_roles completely untouched', function () {
    $role = EmployeeRole::factory()->forCompany($this->aim)->role('MANAGER')
        ->create(['employee_id' => $this->employee->id]);

    xferTo();

    $after = EmployeeRole::withoutGlobalScopes()->find($role->id);

    expect($after->company_id)->toBe($this->aim->id)
        ->and($after->revoked_date)->toBeNull()
        ->and($after->role)->toBe('MANAGER');
});

it('leaves employee_job_functions completely untouched', function () {
    $assignment = EmployeeJobFunction::factory()
        ->forEmployee($this->employee)
        ->performing(JobFunction::factory()->named('Media')->create())
        ->create();

    expect($assignment->company_id)->toBe($this->aim->id);

    xferTo();

    expect(EmployeeJobFunction::withoutGlobalScopes()->find($assignment->id)->company_id)
        ->toBe($this->aim->id);
});

// ═══════════════════════════════════════════════════════════════════════════════
// The rest
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * ⚠ `adr/0010` §2a — the surviving set is SHOWN rather than stored, and this method is the
 * contract §7's screen must honour before the confirm button. If the UI never calls it,
 * nothing was traded for the refused snapshot; something was simply dropped.
 */
it('reports the authority that survives, before and after', function () {
    EmployeeRole::factory()->forCompany($this->aim)->role('MANAGER')
        ->create(['employee_id' => $this->employee->id]);
    EmployeeJobFunction::factory()->forEmployee($this->employee)
        ->performing(JobFunction::factory()->named('Live Host')->create())->create();

    $preview = $this->transfer->survivingAuthority($this->employee);

    expect($preview['roles'])->toHaveCount(1)
        ->and($preview['jobFunctions'])->toHaveCount(1);

    $result = xferTo();

    expect($result['roles'])->toHaveCount(1)
        ->and($result['jobFunctions'])->toHaveCount(1)
        ->and($result['roles']->first()->company_id)->toBe($this->aim->id);
});

/**
 * A transfer reassigns statutory responsibility for EPF, SOCSO and the EA Form between two
 * legal entities. The actor is the only thing distinguishing an ordinary HR transfer from a
 * Master Admin support intervention after the fact (§5.7).
 */
it('audits the transfer against the employee, naming the actor', function () {
    xferTo(reason: 'Restructure');

    $audit = DB::table('audit_logs')->where('action', 'employee.company_transfer')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->field)->toBe('company_id')
        ->and($audit->old_value)->toBe((string) $this->aim->id)
        ->and($audit->new_value)->toBe((string) $this->tursenia->id)
        ->and($audit->user_id)->toBe($this->actor->id)
        ->and($audit->reason)->toBe('Restructure');
});

/**
 * ⚠ Without a structural refusal HR will use a transfer for a rejoin because it is easier, and
 * the break in service that decides leave entitlement disappears silently.
 */
it('refuses a terminal record and names the rejoiner path', function () {
    $resigned = Employee::factory()->forCompany($this->aim)->resigned()
        ->create(['department_id' => $this->fromDept->id]);

    expect(fn () => $this->transfer->execute($resigned, $this->tursenia, $this->toDept->id, '2026-04-01'))
        ->toThrow(InvalidArgumentException::class, 'previous_employee_id');

    expect($resigned->fresh()->company_id)->toBe($this->aim->id);
});

it('refuses a transfer to the company that already employs them', function () {
    expect(fn () => $this->transfer->execute($this->employee, $this->aim, $this->toDept->id, '2026-04-01'))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * ⚠ A half-transferred employee is a state nobody can repair, because no single field says
 * which half ran. Everything lands together or none of it does.
 */
it('leaves nothing behind when the transfer fails partway', function () {
    $family = EmployeeFamilyMember::factory()->forEmployee($this->employee)->create();

    // A department id that cannot exist: the employee save fails on the foreign key after the
    // Action has already begun writing.
    //
    // ⚠ QueryException, not Throwable. Pest treats a string that is not a class-that-extends-
    // Exception as a MESSAGE to search for, and Throwable is an interface — so
    // toThrow(Throwable::class) silently becomes "does the message contain 'Throwable'" and
    // fails for a reason that has nothing to do with the rollback (conventions.md §9: red for
    // the wrong reason is as undetectable as green for the wrong one).
    expect(fn () => $this->transfer->execute($this->employee, $this->tursenia, 999999, '2026-04-01'))
        ->toThrow(Illuminate\Database\QueryException::class);

    expect($this->employee->fresh()->company_id)->toBe($this->aim->id)
        ->and(EmployeeFamilyMember::withoutGlobalScopes()->find($family->id)->company_id)->toBe($this->aim->id)
        ->and(EmployeeStatusHistory::withoutGlobalScopes()->count())->toBe(0)
        ->and(DB::table('audit_logs')->count())->toBe(0);
});
