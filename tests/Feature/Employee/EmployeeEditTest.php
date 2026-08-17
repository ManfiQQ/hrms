<?php

use App\Livewire\Employees\EmployeeEdit;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\EmployeeStatusHistory;
use App\Models\Nationality;
use App\Models\Position;
use App\Models\User;
use Livewire\Livewire;

/**
 * The edit screen — §5.1, §6.4, and `adr/0014` extended to writing.
 *
 * ⚠ HALF OF WHAT THIS FILE PROTECTS IS ABSENCE. `phone_no` and `staff_status` must not be
 * renderable here under any condition — both have their own write path, and a second path to one
 * fact is the shape this project refuses. "Absent" is the hardest property to keep, because
 * nothing breaks when it stops being true.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

    $this->dept = Department::factory()->shared()->create(['name' => 'Logistics']);
    $this->otherDept = Department::factory()->shared()->create(['name' => 'Finance']);
    $this->malaysia = Nationality::factory()->named('Malaysia')->create();

    $hrEmployee = Employee::factory()->forCompany($this->ahs)->create(['department_id' => $this->dept->id]);
    EmployeeRole::factory()->forCompany($this->ahs)->role('HR')->create(['employee_id' => $hrEmployee->id]);
    $this->hr = User::factory()->forEmployee($hrEmployee)->create();

    $this->subject = Employee::factory()->forCompany($this->aim)->create([
        'department_id' => $this->dept->id,
        'nationality_id' => $this->malaysia->id,
        'ic_no' => '950412145501',
        'level' => 'STAFF',
    ]);
});

function editForm(?Employee $employee = null): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::actingAs(test()->hr)
        ->test('employees.employee-edit', ['employee' => $employee ?? test()->subject]);
}

// ─── Absence ────────────────────────────────────────────────────────────────────────────────

/**
 * ⚠ `phone_no` IS THE LOGIN USERNAME AND IT IS NOT ON THIS RECORD (§6.4, `adr/0006`). A field here
 * would be a second place to change one credential, and the employee would be locked out of their
 * own account with nothing to notice.
 *
 * ⚠ `staff_status` HAS `ChangeEmployeeStatus`, which validates the BR-2 transition, writes the
 * ledger row and performs the BR-A15 freeze together. Setting it on the model here would freeze
 * nobody and record nothing.
 */
it('renders no phone number and no status field, and refuses to write either', function () {
    $html = editForm()->html();

    expect($html)->not->toContain('phone_no')
        ->and($html)->not->toContain('staff_status');

    // ⚠ AND POSTING THEM DIRECTLY IS DISCARDED. `$form` is a public property, so a crafted
    // Livewire request can set any key in it — the intersection with the policy's answer is what
    // stops that, not the absence of a rendered input.
    $before = $this->subject->staff_status;

    editForm()
        ->set('form.staff_status', 'RESIGNED')
        ->call('save');

    expect($this->subject->fresh()->staff_status)->toBe($before)
        ->and(User::query()->where('employee_id', $this->subject->id)->count())->toBe(0);
});

/**
 * ⚠ THE SUPERVISORY TIER CANNOT REACH THIS SCREEN AT ALL, and the pair matters: `adr/0014` gives
 * them four readable fields, and §6's matrix never gave them edit. A supervisor who could open the
 * form would be offered the four to write.
 */
it('refuses the screen to a supervisor who may read the record', function () {
    $supervisorEmployee = Employee::factory()->forCompany($this->aim)->create(['department_id' => $this->dept->id]);
    EmployeeRole::factory()->forCompany($this->aim)->role('SUPERVISOR')
        ->create(['employee_id' => $supervisorEmployee->id]);
    $supervisor = User::factory()->forEmployee($supervisorEmployee)->create();

    $supervised = Employee::factory()->forCompany($this->aim)->reportingTo($supervisorEmployee)
        ->create(['department_id' => $this->dept->id]);

    Livewire::actingAs($supervisor)
        ->test('employees.employee-edit', ['employee' => $supervised])
        ->assertForbidden();
});

/**
 * ⚠ `ASSISTANT_DIRECTOR` IS ACCEPTED, AND THIS IS THE SECOND DIRECTION THE GUARD NEEDS. Without
 * it the tests above prove only that nobody can write anything. `adr/0014` decision 1 puts them on
 * the administrative tier reading all twelve fields, and §6.4 gives them edit — so they write the
 * statutory and bank fields too, and denying that would be a new rule no document states.
 */
it('accepts ASSISTANT_DIRECTOR and offers it every writable field', function () {
    $adEmployee = Employee::factory()->forCompany($this->ahs)->create(['department_id' => $this->dept->id]);
    EmployeeRole::factory()->forCompany($this->ahs)->role('ASSISTANT_DIRECTOR')
        ->create(['employee_id' => $adEmployee->id]);
    $ad = User::factory()->forEmployee($adEmployee)->create();

    $component = Livewire::actingAs($ad)->test('employees.employee-edit', ['employee' => $this->subject]);

    $editable = $component->instance()->editableFields();

    expect($editable)->toContain('ic_no', 'epf_no', 'socso_no', 'tax_no', 'bank_name', 'bank_account_no')
        ->and($editable)->not->toContain('phone_no');

    $component->set('form.bank_account_no', '5140-2233-4455')->call('save')->assertHasNoErrors();

    expect($this->subject->fresh()->bank_account_no)->toBe('5140-2233-4455');
});

// ─── The ledger fields ──────────────────────────────────────────────────────────────────────

/**
 * ⚠ THE THREE LEDGER FIELDS GO THROUGH `ChangeEmployeeAssignment`, NOT ONTO THE MODEL (§5.3). A
 * column write here would leave a record whose history has a gap exactly where a promotion was.
 */
it('writes a dated ledger row when the department changes', function () {
    editForm()
        ->set('form.department_id', (string) $this->otherDept->id)
        ->set('effective_date', '2026-09-01')
        ->call('save')
        ->assertHasNoErrors();

    $row = EmployeeStatusHistory::query()
        ->where('employee_id', $this->subject->id)
        ->where('change_type', 'DEPARTMENT')
        ->sole();

    expect($this->subject->fresh()->department_id)->toBe($this->otherDept->id)
        ->and($row->effective_date->toDateString())->toBe('2026-09-01')
        ->and((int) $row->new_value)->toBe($this->otherDept->id);
});

/**
 * ⚠ AN UNCHANGED LEDGER FIELD MUST NOT REACH THE ACTION, because it THROWS on a no-op — a ledger
 * row for a change that did not happen would put an event in the record that never occurred. A
 * form posts every field, so most saves arrive here with nothing to do.
 */
it('writes no ledger row and does not throw when a ledger field is resubmitted unchanged', function () {
    editForm()
        ->set('form.department_id', (string) $this->dept->id)
        ->set('form.bank_name', 'Maybank')
        ->call('save')
        ->assertHasNoErrors();

    expect(EmployeeStatusHistory::query()->where('employee_id', $this->subject->id)->count())->toBe(0)
        ->and($this->subject->fresh()->bank_name)->toBe('Maybank');
});

/**
 * ⚠ ONE TRANSACTION AROUND BOTH KINDS OF WRITE. `ChangeEmployeeAssignment` opens its own, which
 * NESTS here rather than committing independently — so a save that fails after a ledger row was
 * written leaves no ledger row behind. Without the wrapping transaction the department change is
 * committed and the record ends up describing a move whose other half was rolled back.
 *
 * ⚠ THE FAILURE IS FORCED THROUGH A DIVERGENCE THE FormRequest ALREADY DOCUMENTS, because an
 * invalid value cannot be used: validation runs first and refuses before any transaction opens,
 * which is the whole point of the two sequence tests on the create screen. `address` is the one
 * field where the rule and the column measure DIFFERENT THINGS — `max:65535` counts CHARACTERS and
 * MySQL bounds TEXT in BYTES — so a multibyte address at the extreme passes validation and fails on
 * write. `EmployeeStoreRequest` says so in as many words.
 */
it('rolls the ledger row back when the write after it fails', function () {
    // ⚠ 40 000 TWO-byte characters — 80 000 bytes against a 65 535-byte TEXT column, and 40 000
    // against a rule that counts 65 535 CHARACTERS. Measured: 30 000 of these is 60 000 bytes and
    // MySQL accepts it, which is how the first version of this test asserted a throw that never
    // came. The margin is the point, so the number is not rounded down.
    $tooLongInBytes = str_repeat('م', 40000);

    expect(fn () => editForm()
        ->set('form.department_id', (string) $this->otherDept->id)
        ->set('form.address', $tooLongInBytes)
        ->call('save'))->toThrow(Illuminate\Database\QueryException::class);

    expect(EmployeeStatusHistory::query()->where('employee_id', $this->subject->id)->count())->toBe(0)
        ->and($this->subject->fresh()->department_id)->toBe($this->dept->id)
        ->and($this->subject->fresh()->address)->not->toBe($tooLongInBytes);
});

// ─── Nationality, and the asymmetry `adr/0013` already decided ──────────────────────────────

/**
 * ⚠ THE POLICY'S DISPLAY KEY IS `nationality`; THE COLUMN IS `nationality_id`. Binding the display
 * key fails silently three times over — the rules see no such field and `sometimes` skips them,
 * the intersection keeps a key that is not a column, and `fill()` drops it because it is not
 * fillable. Three no-ops, and the field simply never changes.
 */
it('saves the nationality through the column name, not the display key', function () {
    $indonesia = Nationality::factory()->named('Indonesia')->create();

    expect(editForm()->instance()->editableFields())->toContain('nationality_id')
        ->and(editForm()->instance()->editableFields())->not->toContain('nationality');

    editForm()
        ->set('form.nationality_id', (string) $indonesia->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->subject->fresh()->nationality_id)->toBe($indonesia->id);
});

/**
 * ⚠ THE ASYMMETRY WITH THE CREATE FORM IS `adr/0013` DECISION 6 AND WAS DECIDED BEFORE THIS SCREEN
 * EXISTED. Registration refuses a withdrawn nationality outright, because deactivation exists to
 * remove a value from the picker. Editing must still offer the one this employee ALREADY HOLDS:
 * otherwise the select cannot render their current value, and HR who came to fix a bank number
 * would silently change their nationality on save.
 *
 * ⚠ THE FORM AND THE RULE MUST AGREE. `EmployeeUpdateRequest::selectableNationality()` admits the
 * held value; a form that could not offer it would make that rule unreachable, which is a field
 * nobody can save.
 */
it('keeps offering a withdrawn nationality to the employee who already holds it', function () {
    $burma = Nationality::factory()->named('Burma')->create();
    $held = Employee::factory()->forCompany($this->aim)->create([
        'department_id' => $this->dept->id,
        'nationality_id' => $burma->id,
        'ic_no' => '880202101234',
    ]);
    $burma->delete();

    $component = editForm($held);

    // Offered here...
    $component->assertSee('Burma');

    // ...and accepted on save, unchanged, so an unrelated edit does not rewrite it.
    $component->set('form.bank_name', 'CIMB')->call('save')->assertHasNoErrors();

    expect($held->fresh()->nationality_id)->toBe($burma->id)
        ->and($held->fresh()->bank_name)->toBe('CIMB');

    // ⚠ The paired negative: it is NOT offered to somebody who does not hold it, or the assertion
    // above would pass against a form that offers every withdrawn row to everybody.
    editForm()->assertDontSee('Burma');
});

// ─── Binding ────────────────────────────────────────────────────────────────────────────────

/** ⚠ `.blur`, not `.live` — see the create screen. This form is the same size. */
it('binds every field on blur', function () {
    $html = editForm()->html();

    expect($html)->toContain('wire:model.blur="form.ic_no"')
        ->and($html)->toContain('wire:model.blur="effective_date"')
        ->and($html)->not->toContain('wire:model.live');
});

/**
 * ⚠ THE LEDGER FIELD MAP MUST NOT GROW A FOURTH ENTRY WITHOUT AN ACTION BEHIND IT. `STAFF_STATUS`
 * is deliberately absent, and `CORE_ROLE` is not a change type at all (`adr/0003` decision 8).
 */
it('routes exactly three fields through the assignment ledger', function () {
    expect(EmployeeEdit::LEDGER_FIELDS)->toBe([
        'position_id' => 'POSITION',
        'department_id' => 'DEPARTMENT',
        'level' => 'LEVEL',
    ]);
});
