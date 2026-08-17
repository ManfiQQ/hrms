<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Nationality;
use App\Models\PolicyConfiguration;
use App\Models\Sequence;
use App\Models\User;
use App\Policies\EmployeePolicy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The registration screen — §5.1, and `adr/0015` decision 5's rejoiner block.
 *
 * ⚠ WHAT THESE TESTS PROTECT IS MOSTLY ORDER AND ABSENCE, not output. The screen itself writes
 * nothing: `CreateEmployee` does, and it is already covered. What can only be tested here is that
 * validation refuses BEFORE the Action opens a transaction, that abandoning the form leaves
 * nothing behind, and that the rejoiner search is authorised on every call rather than once.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS', 'name' => 'AL HADDAD SUCCESS SDN BHD']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM', 'name' => 'AL HADDAD INTEGRATED MARKETING']);

    $this->dept = Department::factory()->shared()->create(['name' => 'Logistics']);
    $this->nationality = Nationality::factory()->named('Malaysia')->create();

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

    $hrEmployee = Employee::factory()->forCompany($this->ahs)->create(['department_id' => $this->dept->id]);
    EmployeeRole::factory()->forCompany($this->ahs)->role('HR')->create(['employee_id' => $hrEmployee->id]);
    $this->hr = User::factory()->forEmployee($hrEmployee)->create();
});

/** A complete, valid registration, ready to have one field broken. */
function creationForm(): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::actingAs(test()->hr)->test('employees.employee-create')
        ->set('company_id', (string) test()->aim->id)
        ->set('full_name', 'Nurul Aina binti Rahman')
        ->set('phone_no', '012-345 6789')
        ->set('ic_no', '950412145501')
        ->set('date_of_birth', '1995-04-12')
        ->set('gender', 'FEMALE')
        ->set('nationality_id', (string) test()->nationality->id)
        ->set('department_id', (string) test()->dept->id);
}

/**
 * The `sequences` row's current value, or null when the row does not exist yet.
 *
 * ⚠ THE ROW IS CREATED LAZILY BY THE GENERATOR, WHICH IS WHY THIS IS NULLABLE — and its absence
 * is a stronger assertion than any number: it means nothing has ever claimed a number on this
 * table. Casting a missing row to `(int) 0` and doing arithmetic on it is how the first version
 * of these tests compared 2 against 1 and reported a working screen as broken.
 */
function nextEmployeeNo(): ?int
{
    $value = Sequence::query()->where('key', 'employee_no')->value('next_value');

    return $value === null ? null : (int) $value;
}

// ─── The sequence, and where validation has to sit ───────────────────────────────────────────

/**
 * ⚠ THE ONE TEST THAT KEEPS THE ORDER FROM BEING REVERSED. `CreateEmployee` claims the
 * `employee_no` from the `sequences` row under `lockForUpdate()` INSIDE its transaction, so
 * validation has to refuse before the Action is called at all. Move `Validator::validate()` below
 * `execute()` and this goes red.
 *
 * ⚠ TWO THINGS ARE ASSERTED AT ONCE, AND BOTH ARE WORTH HAVING. That the sequence has not moved
 * says validation refused before the door. `adr/0003` decision 9 is why it matters: a number is
 * retired permanently and never reissued, so a burned one is a permanent gap in a sequence that
 * must never rewind.
 */
it('does not move the employee_no sequence when the payload is rejected', function () {
    $before = nextEmployeeNo();

    creationForm()
        ->set('ic_no', '')
        ->set('passport_no', '')
        ->call('save')
        ->assertHasErrors(['ic_no', 'passport_no']);

    // ⚠ The row is still ABSENT, not merely unchanged — nothing ever reached the generator.
    expect(nextEmployeeNo())->toBe($before)
        ->and(nextEmployeeNo())->toBeNull()
        ->and(Employee::withoutGlobalScopes()->count())->toBe(1);
});

/**
 * ⚠ THE COMPLEMENT, AND WITHOUT IT THE TEST ABOVE PASSES AGAINST A FORM THAT CAN NEVER SAVE. A
 * sequence that never moves is exactly what a permanently broken screen produces.
 */
it('moves the sequence exactly once on a registration that succeeds', function () {
    expect(nextEmployeeNo())->toBeNull();

    creationForm()->call('save')->assertHasNoErrors();

    // ⚠ The FIRST number is AHS-0001 and the row now points at the SECOND. Asserting the issued
    // number as well as the counter is what distinguishes "the sequence moved" from "the sequence
    // moved by the right amount, starting where it should".
    expect(Employee::withoutGlobalScopes()->where('full_name', 'Nurul Aina binti Rahman')->value('employee_no'))
        ->toBe('AHS-0001')
        ->and(nextEmployeeNo())->toBe(2);

    creationForm()->set('ic_no', '880202101234')->set('phone_no', '0111112222')->call('save')->assertHasNoErrors();

    expect(nextEmployeeNo())->toBe(3);
});

/**
 * ⚠ CANCEL BEFORE SAVE LEAVES ZERO TRACE — the property that disappears the moment somebody moves
 * the marking out of `CreateEmployee`'s transaction. Three things are asserted separately because
 * they fail separately: no account row, no sequence movement, and no prior record superseded.
 */
it('leaves nothing behind when the form is abandoned before saving', function () {
    $prior = Employee::factory()->forCompany($this->aim)->resigned()
        ->create(['department_id' => $this->dept->id, 'ic_no' => '900101145501']);
    $priorAccount = User::factory()->forEmployee($prior)->create(['phone_no' => '0198887766']);

    $usersBefore = User::query()->count();
    $employeesBefore = Employee::withoutGlobalScopes()->count();
    $sequenceBefore = nextEmployeeNo();
    $auditBefore = DB::table('audit_logs')->count();

    // Everything short of pressing Save, including the rejoiner search — which reads, and must
    // write nothing.
    creationForm()
        ->set('ic_no', '900101145501')
        ->set('phone_no', '0198887766')
        ->set('has_worked_here_before', true)
        ->set('prior_identifier', '900101145501')
        ->call('findPriorEmployment')
        ->assertSet('previous_employee_id', $prior->id);

    expect(User::query()->count())->toBe($usersBefore)
        ->and(Employee::withoutGlobalScopes()->count())->toBe($employeesBefore)
        ->and(nextEmployeeNo())->toBe($sequenceBefore)
        ->and(DB::table('audit_logs')->count())->toBe($auditBefore)
        ->and($prior->fresh()->superseded_at)->toBeNull()
        ->and($priorAccount->fresh()->superseded_at)->toBeNull();
});

// ─── The rejoiner block ─────────────────────────────────────────────────────────────────────

it('registers a rejoiner carrying the same IC and phone number as their prior record', function () {
    $prior = Employee::factory()->forCompany($this->aim)->resigned()
        ->create(['department_id' => $this->dept->id, 'ic_no' => '900101145501']);
    User::factory()->forEmployee($prior)->create(['phone_no' => '0198887766']);

    creationForm()
        ->set('ic_no', '900101145501')
        ->set('phone_no', '0198887766')
        ->set('has_worked_here_before', true)
        ->set('prior_identifier', '900101145501')
        ->call('findPriorEmployment')
        ->call('save')
        ->assertHasNoErrors();

    $rejoiner = Employee::withoutGlobalScopes()->where('previous_employee_id', $prior->id)->sole();

    expect($rejoiner->ic_no)->toBe('900101145501')
        ->and($prior->fresh()->superseded_at)->not->toBeNull()
        ->and($prior->fresh()->ic_no)->toBe('900101145501');
});

/**
 * ⚠ WITHOUT THE CHECKBOX A DUPLICATE IC IS REFUSED, and that refusal is the protection against a
 * genuine duplicate — two records for one person created by accident. Asking first is what
 * separates a rejoin from a mistake.
 */
it('refuses a duplicate IC when the rejoiner box is not ticked', function () {
    Employee::factory()->forCompany($this->aim)->resigned()
        ->create(['department_id' => $this->dept->id, 'ic_no' => '900101145501']);

    creationForm()
        ->set('ic_no', '900101145501')
        ->call('save')
        ->assertHasErrors('ic_no');
});

/**
 * ⚠ CLEARING THE BOX CLEARS THE LINK. Leaving `previous_employee_id` set behind an unticked box
 * would file a rejoiner as one silently, and `CreateEmployee` would supersede a record nobody
 * meant to touch.
 */
it('drops the link when the rejoiner box is unticked again', function () {
    $prior = Employee::factory()->forCompany($this->aim)->resigned()
        ->create(['department_id' => $this->dept->id, 'ic_no' => '900101145501']);

    creationForm()
        ->set('has_worked_here_before', true)
        ->set('prior_identifier', '900101145501')
        ->call('findPriorEmployment')
        ->assertSet('previous_employee_id', $prior->id)
        ->set('has_worked_here_before', false)
        ->assertSet('previous_employee_id', null)
        ->assertSet('prior_identifier', '');
});

/**
 * ⚠ THE BLANK SEARCH IS A MESSAGE, NOT A 500 AND NOT A RANDOM LINK. The service throws, because
 * `where('ic_no', null)` compiles to `IS NULL` and would match every passport-only employee.
 */
it('reports a blank search instead of linking to whatever it finds', function () {
    Employee::factory()->forCompany($this->aim)->resigned()
        ->create(['department_id' => $this->dept->id, 'ic_no' => null, 'passport_no' => 'B11111111']);

    creationForm()
        ->set('has_worked_here_before', true)
        ->set('prior_identifier', '')
        ->call('findPriorEmployment')
        ->assertSet('previous_employee_id', null)
        ->assertSet('priorEmployment', null)
        ->assertSee('Type an IC, passport or phone number');
});

/**
 * ⚠ AUTHORISED ON EVERY CALL, NOT ONCE AT MOUNT. Every Livewire action is its own request, so a
 * mount-time-only check would leave the lookup callable for the life of the page by an account
 * whose authority was revoked in between. It is also why there is no HTTP route for it.
 */
it('refuses the rejoiner search to an account that may not register anybody', function () {
    $ordinary = User::factory()->forEmployee(
        Employee::factory()->forCompany($this->aim)->create(['department_id' => $this->dept->id])
    )->create();

    Livewire::actingAs($ordinary)->test('employees.employee-create')->assertForbidden();
});

// ─── What the form offers ───────────────────────────────────────────────────────────────────

/**
 * ⚠ THE TWO FIELD LISTS MUST AGREE OR THE FORM DRIFTS FROM THE POLICY. `writableFields()` on the
 * create screen cannot use `writableFieldsFor()` — there is no subject yet, and an unsaved model
 * would make `$actor->employee_id === $employee->id` a false "my own record" match for any account
 * holding no employee record. So the two are derived separately and asserted equal here.
 */
it('offers exactly the fields the policy would allow on the record it creates', function () {
    creationForm()->call('save')->assertHasNoErrors();

    $created = Employee::withoutGlobalScopes()->where('full_name', 'Nurul Aina binti Rahman')->sole();

    $fromComponent = Livewire::actingAs($this->hr)->test('employees.employee-create')
        ->instance()->writableFields();

    expect($fromComponent)
        ->toBe(app(EmployeePolicy::class)->writableFieldsFor($this->hr, $created));
});

/**
 * ⚠ `nationality_id` IS NOT NULL AND THE FORM MUST BE ABLE TO SEND IT — a form that cannot submit
 * a required column is a form that cannot save anything. Paired with the withdrawn case below,
 * because "the dropdown renders" passes just as well against a list that offers everything.
 */
it('offers only nationalities that have not been withdrawn', function () {
    $withdrawn = Nationality::factory()->named('Burma')->create();
    $withdrawn->delete();

    $rendered = Livewire::actingAs($this->hr)->test('employees.employee-create');

    $rendered->assertSee('Malaysia')->assertDontSee('Burma');

    // And the rule agrees — deactivation exists to remove a value from the picker, so a new
    // record that could still select one would make the withdrawal decorative.
    creationForm()
        ->set('nationality_id', (string) $withdrawn->id)
        ->call('save')
        ->assertHasErrors('nationality_id');
});

/**
 * ⚠ NO PHONE FIELD WOULD MEAN NO ACCOUNT, AND NO ACCOUNT MEANS PAYROLL BLOCKS. It is the login
 * username on `users` rather than an employee column, which is why it is absent from
 * `writableFields()` and passed to the Action separately — the one field on this form that is not
 * part of the record it creates.
 */
it('collects the phone number even though it is not an employee column', function () {
    $rendered = Livewire::actingAs($this->hr)->test('employees.employee-create');

    $rendered->assertSee('login username');

    expect(Livewire::actingAs($this->hr)->test('employees.employee-create')->instance()->writableFields())
        ->not->toContain('phone_no');
});

/** ⚠ Every field is `.blur`. On a form this size `.live` is a request per keystroke. */
it('binds every field on blur rather than on every keystroke', function () {
    $html = Livewire::actingAs($this->hr)->test('employees.employee-create')->html();

    expect($html)->toContain('wire:model.blur="ic_no"')
        ->and($html)->toContain('wire:model.blur="full_name"')
        // The one deliberate exception: the checkbox controls what is rendered, so it must round
        // trip immediately.
        ->and($html)->toContain('wire:model.live="has_worked_here_before"')
        ->and(substr_count($html, 'wire:model.live'))->toBe(1);
});
