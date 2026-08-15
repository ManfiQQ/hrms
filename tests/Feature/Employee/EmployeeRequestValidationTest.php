<?php

use App\Http\Requests\Employee\EmployeeStoreRequest;
use App\Http\Requests\Employee\EmployeeUpdateRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;

/**
 * EmployeeStoreRequest and EmployeeUpdateRequest — §5.1.
 *
 * ⚠ ALL VALIDATION LIVES IN A FormRequest (conventions.md §1). What does NOT is the BR-2
 * status lifecycle, which is service-layer: a rule enforced only at the edge is one an
 * importer, a seeder or a future API route walks straight past.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->tursenia = Company::factory()->subsidiary($this->ahs)->create(['code' => 'TURSENIA']);

    $this->shared = Department::factory()->shared()->create(['name' => 'Logistics']);

    // Employed by AHS, so the read scope is the whole group.
    $hrEmployee = Employee::factory()->forCompany($this->ahs)
        ->create(['department_id' => $this->shared->id]);
    EmployeeRole::factory()->forCompany($this->ahs)->role('HR')
        ->create(['employee_id' => $hrEmployee->id]);

    $this->hr = User::factory()->forEmployee($hrEmployee)->create();

    // The group-wide vocabulary a registration must choose from (adr/0013 decision 6).
    $this->nationality = Nationality::factory()->named('Malaysia')->create();
});

function validStorePayload(array $overrides = []): array
{
    return array_merge([
        'company_id' => test()->aim->id,
        'full_name' => 'Nurul Aina binti Rahman',
        'phone_no' => '012-345 6789',

        // ⚠ The three columns adr/0013 made NOT NULL. They are part of a COMPLETE payload
        // from 2026-08-14 onward: a registration without them is refused by the database
        // regardless of what the FormRequest says, so a rule that omitted them would hand the
        // user a raw constraint violation instead of a validation message.
        'date_of_birth' => '1995-04-12',
        'gender' => 'FEMALE',
        'nationality_id' => test()->nationality->id,
        'department_id' => test()->shared->id,
        'level' => 'STAFF',
        'employment_type' => 'FULL-TIME',
        'staff_status' => 'PROBATION',
        'attendance_type' => 'FIXED',
        'work_start_time' => '09:00',
        'work_end_time' => '18:00',
        'ot_after_time' => '18:00',
        'working_days' => ['MON', 'TUE', 'WED', 'THU', 'FRI'],
        'offday' => ['SUN'],
    ], $overrides);
}

function storeErrors(array $payload, User $actor): array
{
    $request = EmployeeStoreRequest::create('/employees', 'POST', $payload);
    $request->setUserResolver(fn () => $actor);

    return Validator::make($payload, $request->rules())->errors()->keys();
}

function updateErrors(array $payload, Employee $employee, User $actor): array
{
    $request = EmployeeUpdateRequest::create('/employees/'.$employee->id, 'PATCH', $payload);
    $request->setUserResolver(fn () => $actor);

    $route = new Route(['PATCH'], '/employees/{employee}', []);
    $route->bind($request);
    $route->setParameter('employee', $employee);
    $request->setRouteResolver(fn () => $route);

    return Validator::make($payload, $request->rules())->errors()->keys();
}

it('accepts a complete payload', function () {
    expect(storeErrors(validStorePayload(), $this->hr))->toBe([]);
});

/**
 * ⚠ BR-12, AND THIS IS THE ONE MOST LIKELY TO BE "FIXED" BY A WELL-MEANING RULE. An employee
 * of TURSENIA sitting in the shared Logistics branch is a CORRECT record. Validation must not
 * require the org unit to match the employer — org placement is independent of who pays them
 * (`adr/0002` decisions 2–3).
 */
it('accepts an employee whose branch and department belong elsewhere', function () {
    $aimBranch = Branch::factory()->create(['company_id' => $this->aim->id]);

    $errors = storeErrors(validStorePayload([
        'company_id' => $this->tursenia->id,
        'branch_id' => $aimBranch->id,
        'department_id' => $this->shared->id,
    ]), $this->hr);

    expect($errors)->toBe([]);
});

/**
 * ⚠ NULL MEANS "NOT APPLICABLE", NOT "UNKNOWN" AND NOT "ZERO". Forcing a value for a FLEXIBLE
 * employee would put a real-looking time where future code reads a genuine OT threshold,
 * silently computing overtime for someone whose OT is decided by a human — and a wrong number
 * is worse than an absent one, because only the absent one can be detected.
 *
 * schema.md rules out a DATABASE constraint for this, because it is conditional on another
 * column. It does not rule out validation, and a FormRequest is the validation layer.
 */
it('requires an OT threshold for FIXED attendance and permits none for FLEXIBLE', function () {
    expect(storeErrors(validStorePayload(['attendance_type' => 'FIXED', 'ot_after_time' => null]), $this->hr))
        ->toContain('ot_after_time');

    expect(storeErrors(validStorePayload(['attendance_type' => 'FLEXIBLE', 'ot_after_time' => null]), $this->hr))
        ->toBe([]);
});

/**
 * ⚠ Bounded by the read scope rather than trusted. §5.1 says company_id never comes from
 * request input — written when scope was one company per account. adr/0004 decision 1 made it
 * derived, so a group-level HR must choose; the rule is preserved by bounding the choice.
 */
it('refuses a company outside the actor read scope', function () {
    $subsidiaryHr = User::factory()->forEmployee(
        tap(Employee::factory()->forCompany($this->aim)->create(['department_id' => $this->shared->id]),
            fn ($e) => EmployeeRole::factory()->forCompany($this->aim)->role('HR')->create(['employee_id' => $e->id]))
    )->create();

    expect(storeErrors(validStorePayload(['company_id' => $this->tursenia->id]), $subsidiaryHr))
        ->toContain('company_id');
});

/**
 * ⚠ The number is the login username, and a placeholder is not the workaround: it would
 * occupy the unique index and hand one employee's username to another (BR-A1).
 */
it('refuses a phone number BR-A1 would not accept', function () {
    expect(storeErrors(validStorePayload(['phone_no' => '123']), $this->hr))->toContain('phone_no');
    expect(storeErrors(validStorePayload(['phone_no' => '+60123456789']), $this->hr))->toBe([]);
});

it('refuses a confirmation date before probation ends', function () {
    expect(storeErrors(validStorePayload([
        'probation_end_date' => '2026-06-01',
        'confirmation_date' => '2026-05-01',
    ]), $this->hr))->toContain('confirmation_date');
});

it('refuses a terminal status on the registration form', function () {
    // RESIGNED and TERMINATED freeze the account in the same transaction and are reached
    // through ChangeEmployeeStatus, not through a registration form (BR-2).
    expect(storeErrors(validStorePayload(['staff_status' => 'RESIGNED']), $this->hr))
        ->toContain('staff_status');
});

/**
 * ⚠ employee_no is absent from the rules by design. The number comes from the `sequences` row
 * taken with lockForUpdate() inside the insert's transaction; accepting one from the request
 * would let two concurrent registrations claim the same number (BR-13).
 */
it('ignores an employee_no supplied by the caller', function () {
    expect(storeErrors(validStorePayload(['employee_no' => 'AHS-9999']), $this->hr))->toBe([]);

    $request = EmployeeStoreRequest::create('/employees', 'POST', validStorePayload());
    $request->setUserResolver(fn () => $this->hr);

    expect(array_keys($request->rules()))->not->toContain('employee_no')
        ->and(array_keys($request->rules()))->not->toContain('staff_status_history');
});

/**
 * ⚠ ONE FIELD AT A TIME, NEVER ALL THREE AT ONCE. A payload missing every identity field goes
 * red while only one of the three rules exists, so it would prove that *a* rule is present and
 * nothing about which. Dropping one and asserting the error set is EXACTLY that field is what
 * makes each rule individually load-bearing.
 *
 * They are here because the columns are NOT NULL as of `adr/0013`: a request that omitted them
 * would reach the insert and come back as a raw constraint violation — a 500 rather than a
 * message naming the field.
 */
it('requires each of the three identity fields on its own', function () {
    foreach (['date_of_birth', 'gender', 'nationality_id'] as $field) {
        $payload = validStorePayload();
        unset($payload[$field]);

        expect(storeErrors($payload, $this->hr))
            ->toBe([$field], "omitting {$field} must fail on {$field} alone");
    }
});

it('refuses a date of birth in the future', function () {
    // The one bound that needs no business decision. There is deliberately NO minimum-age
    // rule: the Employment Act sets a working age, but the exact bound is a decision nobody
    // has made here, and inventing one in a FormRequest would settle it by accident.
    expect(storeErrors(validStorePayload(['date_of_birth' => now()->addDay()->toDateString()]), $this->hr))
        ->toBe(['date_of_birth']);
});

/**
 * ⚠ THE ASYMMETRY BETWEEN THE TWO REQUESTS, IN ALL FOUR DIRECTIONS — and the fourth is the one
 * that matters. Without "an employee may not move TO a withdrawn nationality", this suite
 * would pass just as happily against an update rule that accepted every withdrawn row, which
 * would make the withdrawal decorative on the edit path.
 *
 * Registration refuses withdrawn values outright, because removing a value from the picker is
 * what withdrawal IS. The edit path accepts exactly one: the value this employee already
 * holds — otherwise the moment HR withdraws `Myanmar`, every employee holding it becomes
 * uneditable, and an address change is rejected on a field the user never touched.
 */
it('refuses a withdrawn nationality on registration and accepts a live one', function () {
    $withdrawn = Nationality::factory()->named('Myanmar')->create();
    $withdrawn->delete();

    expect(storeErrors(validStorePayload(['nationality_id' => $withdrawn->id]), $this->hr))
        ->toBe(['nationality_id']);

    expect(storeErrors(validStorePayload(['nationality_id' => $this->nationality->id]), $this->hr))
        ->toBe([]);
});

it('lets an employee keep the withdrawn nationality they hold, and no other', function () {
    $held = Nationality::factory()->named('Myanmar')->create();
    $other = Nationality::factory()->named('Burma')->create();

    $employee = Employee::factory()->forCompany($this->aim)->ofNationality($held)
        ->create(['department_id' => $this->shared->id]);

    $held->delete();
    $other->delete();

    expect(updateErrors(['nationality_id' => $held->id], $employee, $this->hr))
        ->toBe([], 'the nationality this employee already holds stays acceptable once withdrawn');

    expect(updateErrors(['nationality_id' => $other->id], $employee, $this->hr))
        ->toBe(['nationality_id'], 'a withdrawn nationality this employee does not hold is refused');

    expect(updateErrors(['nationality_id' => $this->nationality->id], $employee, $this->hr))
        ->toBe([], 'a live nationality is always acceptable');
});

it('refuses an employee as their own supervisor', function () {
    $employee = Employee::factory()->forCompany($this->aim)
        ->create(['department_id' => $this->shared->id]);

    expect(updateErrors(['direct_supervisor_id' => $employee->id], $employee, $this->hr))
        ->toContain('direct_supervisor_id');

    expect(updateErrors(['manager_id' => $employee->id], $employee, $this->hr))
        ->toContain('manager_id');
});

/**
 * ⚠ BR-8's cycle rule, and a cycle does not error anywhere on its own. It produces an
 * approval chain that never terminates, and the routing engine would follow it until
 * something else gave way — which is why the rule says "validated on save".
 */
it('refuses a supervisor chain that would form a cycle', function () {
    $top = Employee::factory()->forCompany($this->aim)->create(['department_id' => $this->shared->id]);
    $middle = Employee::factory()->forCompany($this->aim)->create([
        'department_id' => $this->shared->id,
        'direct_supervisor_id' => $top->id,
    ]);

    // Making `top` report to `middle` closes the loop: top → middle → top.
    expect(updateErrors(['direct_supervisor_id' => $middle->id], $top, $this->hr))
        ->toContain('direct_supervisor_id');
});

it('accepts an ordinary supervisor that closes no loop', function () {
    $top = Employee::factory()->forCompany($this->aim)->create(['department_id' => $this->shared->id]);
    $below = Employee::factory()->forCompany($this->aim)->create(['department_id' => $this->shared->id]);

    expect(updateErrors(['direct_supervisor_id' => $top->id], $below, $this->hr))->toBe([]);
});

/**
 * ⚠ Four fields are absent from the update rules, each closed deliberately: phone_no is not
 * on this record at all (§6.4), employee_no is Master Admin's and audited, staff_status goes
 * through ChangeEmployeeStatus, and company_id is a transfer that cascades four child tables.
 */
it('exposes no rule for the four fields that are not profile edits', function () {
    $employee = Employee::factory()->forCompany($this->aim)
        ->create(['department_id' => $this->shared->id]);

    $request = EmployeeUpdateRequest::create('/employees/'.$employee->id, 'PATCH', []);
    $request->setUserResolver(fn () => $this->hr);

    $route = new Route(['PATCH'], '/employees/{employee}', []);
    $route->bind($request);
    $route->setParameter('employee', $employee);
    $request->setRouteResolver(fn () => $route);

    $fields = array_keys($request->rules());

    expect($fields)->not->toContain('phone_no')
        ->not->toContain('employee_no')
        ->not->toContain('staff_status')
        ->not->toContain('company_id');
});
