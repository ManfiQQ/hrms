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

    // The group-wide vocabulary a registration must choose from (adr/0013 decision 6).
    //
    // ⚠ CREATED BEFORE THE FIRST EMPLOYEE, AND THE ORDER IS LOAD-BEARING — fixed 2026-08-17
    // after this file failed roughly one run in seven. EmployeeFactory resolves its
    // nationality as `Nationality::query()->value('id') ?? Nationality::factory()`, so against
    // an empty table it DRAWS A RANDOM COUNTRY — and roughly one draw in two hundred and fifty
    // is `Malaysia`, which the line below then inserts a second time. Creating it first means
    // the employee reuses this row and nothing is ever drawn.
    $this->nationality = Nationality::factory()->named('Malaysia')->create();

    // Employed by AHS, so the read scope is the whole group.
    $hrEmployee = Employee::factory()->forCompany($this->ahs)
        ->create(['department_id' => $this->shared->id]);
    EmployeeRole::factory()->forCompany($this->ahs)->role('HR')
        ->create(['employee_id' => $hrEmployee->id]);

    $this->hr = User::factory()->forEmployee($hrEmployee)->create();
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

        // ⚠ ONE IDENTITY DOCUMENT IS PART OF A COMPLETE PAYLOAD FROM 2026-08-17 ONWARD
        // (adr/0013 decision 2). Unlike the three above it is not the database asking — both
        // columns are nullable — it is the rule that every person carry one form of
        // identification. Tests that prove the rule remove it explicitly.
        //
        // ⚠ WRITTEN WITHOUT DASHES SINCE adr/0015 decision 3, AND IT WAS WRITTEN WITH THEM
        // UNTIL THEN. This fixture was `950412-14-5501`, and twelve tests in this file went red
        // the moment `digits:12` landed — which is the rule working, not the fixture being
        // awkward. The dashed form was flowing through every one of those payloads, and the
        // separator-free form is what the column now holds.
        'ic_no' => '950412145501',
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

    return Validator::make($payload, $request->rules(), $request->messages())->errors()->keys();
}

function storeValidated(array $payload, User $actor): array
{
    $request = EmployeeStoreRequest::create('/employees', 'POST', $payload);
    $request->setUserResolver(fn () => $actor);

    return Validator::make($payload, $request->rules(), $request->messages())->validated();
}

function updateErrors(array $payload, Employee $employee, User $actor): array
{
    $request = EmployeeUpdateRequest::create('/employees/'.$employee->id, 'PATCH', $payload);
    $request->setUserResolver(fn () => $actor);

    $route = new Route(['PATCH'], '/employees/{employee}', []);
    $route->bind($request);
    $route->setParameter('employee', $employee);
    $request->setRouteResolver(fn () => $route);

    return Validator::make($payload, $request->rules(), $request->messages())->errors()->keys();
}

function updateValidated(array $payload, Employee $employee, User $actor): array
{
    $request = EmployeeUpdateRequest::create('/employees/'.$employee->id, 'PATCH', $payload);
    $request->setUserResolver(fn () => $actor);

    $route = new Route(['PATCH'], '/employees/{employee}', []);
    $route->bind($request);
    $route->setParameter('employee', $employee);
    $request->setRouteResolver(fn () => $route);

    return Validator::make($payload, $request->rules(), $request->messages())->validated();
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

/**
 * The nine columns `adr/0013` added and neither request could accept — 2026-08-17.
 *
 * ⚠ A FIELD WITH NO RULE DOES NOT REACH validated(), SO IT CANNOT BE SAVED AT ALL. That is why
 * these assert the validated payload rather than an empty error set: the bug was never that the
 * fields were rejected — nothing rejected them. They were dropped, silently, by the layer whose
 * output the Action writes.
 */
it('carries every identity and statutory column through registration', function () {
    $submitted = [
        'ic_no' => '950412145501',
        'passport_no' => 'A12345678',
        'permit_expiry' => '2027-03-31',
        'address' => 'No 12, Jalan Melur 3, 68000 Ampang, Selangor',
        'epf_no' => '14725836',
        'socso_no' => 'B1472583',
        'tax_no' => 'SG 10234567890',
        'bank_name' => 'Maybank',
        'bank_account_no' => '512345678901',
        'previous_employee_id' => null,
    ];

    $validated = storeValidated(validStorePayload($submitted), $this->hr);

    foreach ($submitted as $field => $value) {
        expect(array_key_exists($field, $validated))
            ->toBeTrue("{$field} must survive validation, not be dropped");
        expect($validated[$field] ?? null)
            ->toBe($value, "{$field} must arrive at the Action unchanged");
    }
});

it('carries every identity and statutory column through an edit', function () {
    $employee = Employee::factory()->forCompany($this->aim)
        ->create(['department_id' => $this->shared->id, 'ic_no' => '880101101234']);

    $submitted = [
        'passport_no' => 'B98765432',
        'permit_expiry' => '2028-01-15',
        'address' => 'No 5, Lorong Damai, 43000 Kajang, Selangor',
        'epf_no' => '96385274',
        'socso_no' => 'C9638527',
        'tax_no' => 'OG 20987654321',
        'bank_name' => 'CIMB',
        'bank_account_no' => '700123456789',
    ];

    $validated = updateValidated($submitted, $employee, $this->hr);

    foreach ($submitted as $field => $value) {
        expect(array_key_exists($field, $validated))
            ->toBeTrue("{$field} must survive validation on the edit path");
        expect($validated[$field] ?? null)
            ->toBe($value, "{$field} must arrive at the Action unchanged");
    }
});

/**
 * ⚠ adr/0013 decision 2, and the amendment of 2026-08-15 recorded the cost of its absence:
 * *"an employee can be registered with neither an IC nor a passport, and nothing anywhere
 * objects."* These four close it.
 *
 * ⚠ EACH CASE IS ITS OWN ASSERTION AND NOT ONE COMBINED PAYLOAD. Both positives matter
 * separately: a rule written as "ic_no is required" would pass the IC case and fail the
 * passport case, and a suite that only ever submitted an IC could not tell the two apart.
 */
it('registers an employee holding an IC and no passport', function () {
    expect(storeErrors(validStorePayload(['ic_no' => '950412145501', 'passport_no' => null]), $this->hr))
        ->toBe([]);
});

it('registers an employee holding a passport and no IC', function () {
    expect(storeErrors(validStorePayload(['ic_no' => null, 'passport_no' => 'A12345678']), $this->hr))
        ->toBe([]);
});

it('refuses a registration carrying neither identity document, naming both fields', function () {
    $payload = validStorePayload();
    unset($payload['ic_no']);

    expect(storeErrors($payload, $this->hr))
        ->toBe(['ic_no', 'passport_no'], 'either field satisfies the rule, so the form must say so on both');
});

/**
 * ⚠ THE MESSAGE IS PART OF THE RULE HERE, NOT DECORATION, AND IT WAS UNTESTED UNTIL THIS TEST
 * EXISTED. Laravel's own wording states the mechanism and hides the rule — *"the ic no field is
 * required when passport no is not present"* on registration, and on the edit path the bare
 * *"the ic no field is required"* about a column that is nullable, which is simply untrue.
 *
 * Found 2026-08-17 by probing what the harness actually produced: the four helpers were calling
 * `Validator::make($payload, $rules)` with no third argument, so `messages()` existed, shipped,
 * and was exercised by nothing. The helpers now pass it, which is also what the real FormRequest
 * pipeline does.
 */
it('tells HR that either identity document will do, on both paths', function () {
    $payload = validStorePayload();
    unset($payload['ic_no']);

    $request = EmployeeStoreRequest::create('/employees', 'POST', $payload);
    $request->setUserResolver(fn () => $this->hr);

    expect(Validator::make($payload, $request->rules(), $request->messages())->errors()->first('ic_no'))
        ->toContain('at least one form of identification');

    $employee = Employee::factory()->forCompany($this->aim)
        ->create(['department_id' => $this->shared->id, 'ic_no' => '880101101234']);

    $body = ['ic_no' => null];
    $update = EmployeeUpdateRequest::create('/employees/'.$employee->id, 'PATCH', $body);
    $update->setUserResolver(fn () => $this->hr);
    $route = new Route(['PATCH'], '/employees/{employee}', []);
    $route->bind($update);
    $route->setParameter('employee', $employee);
    $update->setRouteResolver(fn () => $route);

    expect(Validator::make($body, $update->rules(), $update->messages())->errors()->first('ic_no'))
        ->toContain('would be left with neither');
});

/**
 * ⚠ THE EMPTY STRING IS WHAT A FORM ACTUALLY POSTS for a box nobody typed in — an omitted key
 * is what a test or an API sends. A rule that catches only the second is one the registration
 * screen walks straight past, which is the whole reason this rule lives on the form.
 */
it('treats an empty identity field as absent, not as a value', function () {
    expect(storeErrors(validStorePayload(['ic_no' => '', 'passport_no' => '']), $this->hr))
        ->toBe(['ic_no', 'passport_no']);
});

/**
 * The edit path asks a different question from the registration path, and the difference is the
 * whole of `identityDocumentRule()`: what will this record HOLD once the payload is applied.
 */
it('refuses an edit that would empty the last identity document', function () {
    $employee = Employee::factory()->forCompany($this->aim)
        ->create(['department_id' => $this->shared->id, 'ic_no' => '880101101234']);

    expect(updateErrors(['ic_no' => null], $employee, $this->hr))
        ->toBe(['ic_no', 'passport_no'], 'the record would be left with no identification at all');
});

it('permits clearing a passport from an employee who still holds an IC', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create([
        'department_id' => $this->shared->id,
        'ic_no' => '880101101234',
        'passport_no' => 'A00000001',
    ]);

    expect(updateErrors(['passport_no' => null], $employee, $this->hr))
        ->toBe([], 'the IC is read from the route model, and it still identifies this person');
});

/**
 * ⚠ THE DELIBERATE HOLE, AND IT IS A DECISION RATHER THAN AN OVERSIGHT — 2026-08-17.
 *
 * The rule fires only when the payload TOUCHES one of the two columns. A record that already
 * holds neither — a legacy import, `CLAUDE.md` §10 question (f) — stays editable, because
 * rejecting an unrelated edit on a field the user never touched is the failure
 * selectableNationality() exists one column over to avoid.
 *
 * Nothing is lost by it: a record can only ENTER that state by having a document emptied, which
 * means the field was submitted, which means the rule above ran. Validation constrains what
 * arrives next; it does not repair what is already stored.
 */
it('lets an unrelated edit through on a record that already holds no identity document', function () {
    $employee = Employee::factory()->forCompany($this->aim)
        ->create(['department_id' => $this->shared->id, 'ic_no' => null, 'passport_no' => null]);

    expect(updateErrors(['bank_name' => 'Maybank'], $employee, $this->hr))
        ->toBe([], 'an address or bank correction must not be rejected on a field nobody touched');
});

/**
 * ⚠ adr/0013 decision 4 — an expired permit blocks NOTHING. It raises a flag and, once the
 * Notification Engine exists, notifies; renewal is the response, not suspension. This test
 * exists to keep a well-meaning `after:today` out of the rules: the record it would refuse is
 * one that legitimately exists.
 */
it('accepts a permit that has already expired', function () {
    expect(storeErrors(validStorePayload(['permit_expiry' => '2020-01-31']), $this->hr))
        ->toBe([]);

    expect(storeErrors(validStorePayload(['permit_expiry' => 'not-a-date']), $this->hr))
        ->toBe(['permit_expiry'], 'nullable and unbounded is not the same as unvalidated');
});

/**
 * ⚠ THE UNIQUE RULE IS NOT THE THING THAT BLOCKS A REJOINER — the INDEX is, and it does so with
 * or without this rule (schema.md under `ic_no`, adr/0003 decision 9). What the rule changes is
 * a raw constraint violation into a message naming the field. The contradiction between the two
 * Accepted ADRs needs an ADR of its own and does not have one.
 */
it('refuses an IC another employee already holds, and accepts the one this employee holds', function () {
    $held = Employee::factory()->forCompany($this->aim)
        ->create(['department_id' => $this->shared->id, 'ic_no' => '770707077777']);

    expect(storeErrors(validStorePayload(['ic_no' => '770707077777']), $this->hr))
        ->toBe(['ic_no']);

    expect(updateErrors(['ic_no' => '770707077777'], $held, $this->hr))
        ->toBe([], 'resubmitting the value this record already holds is not a collision');

    $other = Employee::factory()->forCompany($this->aim)
        ->create(['department_id' => $this->shared->id, 'ic_no' => '660606066666']);

    expect(updateErrors(['ic_no' => '770707077777'], $other, $this->hr))
        ->toBe(['ic_no'], 'another employee holds it');
});

/**
 * BR-13 and `adr/0003` decision 9 — a rejoiner is a NEW record pointing at the old one. The
 * column has existed since 2026-08-12 and nothing could write to it until now.
 *
 * ⚠ An ARCHIVED prior record is the ordinary case, not an error: the old employment ended. The
 * `exists` rule filters neither `deleted_at` nor tenant scope, and both are deliberate — a
 * rejoiner may return to a different group entity.
 */
it('links a rejoiner to a prior record, including an archived one at another company', function () {
    $prior = Employee::factory()->forCompany($this->tursenia)
        ->create(['department_id' => $this->shared->id]);
    $prior->delete();

    expect(storeErrors(validStorePayload(['previous_employee_id' => $prior->id]), $this->hr))
        ->toBe([], 'an archived record at another company is exactly what a rejoiner points at');

    expect(storeErrors(validStorePayload(['previous_employee_id' => 999999]), $this->hr))
        ->toBe(['previous_employee_id']);
});

it('refuses an employee as their own previous record', function () {
    $employee = Employee::factory()->forCompany($this->aim)
        ->create(['department_id' => $this->shared->id, 'ic_no' => '880101101234']);

    expect(updateErrors(['previous_employee_id' => $employee->id], $employee, $this->hr))
        ->toBe(['previous_employee_id']);

    $prior = Employee::factory()->forCompany($this->aim)
        ->create(['department_id' => $this->shared->id]);

    expect(updateErrors(['previous_employee_id' => $prior->id], $employee, $this->hr))
        ->toBe([]);
});

// ─── adr/0015 — the stored form, and uniqueness scoped to live rows ──────────────────────────

/**
 * ⚠ adr/0015 decision 3. An index only constrains values stored IDENTICALLY, so the storage form
 * is part of the constraint rather than a presentation choice. `900101-14-5501` and
 * `900101145501` are two strings and one person.
 *
 * ⚠ AND THE FAILURE IS WORSE THAN A MISSED MATCH. Decision 5 makes the rejoiner search match on
 * identity, so one person written two ways yields TWO CONTRADICTORY ANSWERS: the search reports
 * no prior employment while the unique index refuses the IC as already taken.
 */
it('refuses a dashed IC and accepts the separator-free form', function () {
    expect(storeErrors(validStorePayload(['ic_no' => '950412-14-5501']), $this->hr))
        ->toContain('ic_no');

    expect(storeErrors(validStorePayload(['ic_no' => '950412145501']), $this->hr))
        ->toBe([]);
});

/**
 * ⚠ TWELVE DIGITS IS THE FORMAT'S DEFINITION, NOT AN ASSUMPTION ABOUT IT — a Malaysian IC is
 * YYMMDD-PB-###G. `digits` also means digits-only, so it replaces a character rule rather than
 * sitting beside one, which is why no separate regex exists for this column.
 */
it('refuses an IC that is not twelve digits', function () {
    expect(storeErrors(validStorePayload(['ic_no' => '95041214550']), $this->hr))->toContain('ic_no')
        ->and(storeErrors(validStorePayload(['ic_no' => '9504121455011']), $this->hr))->toContain('ic_no')
        ->and(storeErrors(validStorePayload(['ic_no' => '95041214550A']), $this->hr))->toContain('ic_no');
});

/**
 * ⚠ THE PASSPORT RULE IS DELIBERATELY DIFFERENT, AND APPLYING THE IC RULE HERE WOULD REJECT REAL
 * DOCUMENTS. Passport numbers mix letters and digits, and their length varies by issuing country —
 * so there is no length bound at all, for the same reason this file gives no format rule to the
 * EPF and LHDN numbers: a guessed bound rejects valid values.
 */
it('accepts a passport of letters and digits and refuses one carrying a separator', function () {
    $payload = fn (string $passport) => validStorePayload(['ic_no' => null, 'passport_no' => $passport]);

    expect(storeErrors($payload('A12345678'), $this->hr))->toBe([])
        ->and(storeErrors($payload('X9'), $this->hr))->toBe([])
        ->and(storeErrors($payload('A-1234567'), $this->hr))->toContain('passport_no')
        ->and(storeErrors($payload('A 1234567'), $this->hr))->toContain('passport_no');
});

/**
 * ⚠ THE RULE MUST MATCH THE INDEX OR IT BECOMES THE LAST THING BLOCKING THE FLOW. The index is
 * `UNIQUE ((IF(superseded_at IS NULL, ic_no, NULL)))` since adr/0015, so an unscoped rule here
 * would refuse every rejoiner the database is now willing to accept — and the FormRequest, not
 * the constraint, would be what made the rejoining flow impossible.
 */
it('ignores a superseded record when checking the IC is free', function () {
    $this->actingAs($this->hr);

    $prior = Employee::factory()->forCompany($this->aim)->resigned()
        ->create(['department_id' => $this->shared->id, 'ic_no' => '770707077777']);

    expect(storeErrors(validStorePayload(['ic_no' => '770707077777']), $this->hr))
        ->toContain('ic_no');

    $prior->superseded_at = now();
    $prior->save();

    expect(storeErrors(validStorePayload(['ic_no' => '770707077777']), $this->hr))
        ->toBe([]);
});

/**
 * ⚠ THE RULE THAT DID NOT EXIST ANYWHERE IN `app/` UNTIL 2026-08-17, and its absence is a defect
 * adr/0015's own Context records: `CreateEmployee` failed on `users_phone_no_unique` INSIDE its
 * transaction as a raw 1062, which reaches the user as a 500 rather than a message naming a
 * field. Leaving it out would have fixed half of the problem the ADR names.
 */
it('names the phone number field when a live account already holds it', function () {
    $holder = Employee::factory()->forCompany($this->aim)
        ->create(['department_id' => $this->shared->id]);
    User::factory()->forEmployee($holder)->create(['phone_no' => '0123456789']);

    expect(storeErrors(validStorePayload(['phone_no' => '012-345 6789']), $this->hr))
        ->toContain('phone_no');
});

/**
 * ⚠ THE SAME SCOPE AS THE INDEX. A rejoiner's own frozen account holds their number; it has
 * released the claim, so the rule must not treat it as a collision.
 */
it('ignores a superseded account when checking the phone number is free', function () {
    $this->actingAs($this->hr);

    $holder = Employee::factory()->forCompany($this->aim)->resigned()
        ->create(['department_id' => $this->shared->id]);
    $account = User::factory()->forEmployee($holder)->create(['phone_no' => '0123456789']);

    expect(storeErrors(validStorePayload(['phone_no' => '0123456789']), $this->hr))
        ->toContain('phone_no');

    $account->superseded_at = now();
    $account->save();

    expect(storeErrors(validStorePayload(['phone_no' => '0123456789']), $this->hr))
        ->toBe([]);
});

/**
 * ⚠ WHY THE CHECK IS A CLOSURE AND NOT `unique:users,phone_no`. The stored value is normalised
 * (BR-A1), so a declarative rule would compare the RAW input against a normalised column:
 * `012-345 6789` matches nothing, passes, and then collides at the insert — the exact split
 * BR-A1's one-normaliser rule exists to prevent. Adding a `where()` clause does not repair it
 * either; it ANDs a second condition onto the same column rather than replacing the first.
 */
it('normalises the phone number before checking whether it is taken', function () {
    $holder = Employee::factory()->forCompany($this->aim)
        ->create(['department_id' => $this->shared->id]);
    User::factory()->forEmployee($holder)->create(['phone_no' => '0123456789']);

    // ⚠ Pest's toContain takes NEEDLES, not a failure message — a second argument here would be
    // asserted as a second needle and pass for the wrong reason. Which form failed is recovered
    // by collecting them instead, so the assertion names the written form that got through.
    $accepted = [];

    foreach (['012-345 6789', '+60123456789', '60123456789', '0123456789'] as $written) {
        if (! in_array('phone_no', storeErrors(validStorePayload(['phone_no' => $written]), $this->hr), true)) {
            $accepted[] = $written;
        }
    }

    expect($accepted)->toBe([]);
});
