<?php

use App\Actions\Employee\CreateEmployee;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PolicyConfiguration;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * `adr/0015` — a rejoining employee can be registered at all.
 *
 * ⚠ WHAT WAS BROKEN, BECAUSE THESE TESTS ONLY MAKE SENSE AGAINST IT. `adr/0003` decision 9
 * gives a rejoiner a NEW record with a NEW `employee_no`; they bring the SAME `ic_no`, because a
 * person has one, and the SAME `phone_no`, because it is their number. Both columns were unique,
 * the old rows still held both values — `employees` is soft-deleted at most and `User` has no
 * soft deletes at all — so the second record could not be created. Two Accepted ADRs, neither
 * wrong alone, jointly impossible.
 *
 * ⚠ THE PHONE HALF FAILED WORSE than the IC half: inside `CreateEmployee`'s own transaction, as a
 * raw 1062, because nothing validated it in advance. Both halves are asserted here.
 */
beforeEach(function () {
    // actingAs rather than AuthorshipContext — registration is an act BY somebody, and adr/0009
    // attributes the rows to them. See CreateEmployeeTest for the same reasoning.
    $this->actingAs(User::factory()->masterAdmin()->create());

    $this->company = Company::factory()->create(['code' => 'AHS']);

    foreach ([
        'auth.password.min_length' => '6',
        'auth.throttle.tier_4.attempts' => '12',
        'auth.activation.validity_hours' => '48',
    ] as $key => $value) {
        PolicyConfiguration::create([
            'company_id' => $this->company->id,
            'key' => $key,
            'value' => $value,
            'effective_from' => now()->toDateString(),
        ]);
    }

    $this->action = app(CreateEmployee::class);

    $this->ic = '900101145501';
    $this->phone = '0198887766';
});

/** Register somebody, defaulting to the shared IC and phone this file is about. */
function register(array $overrides = [], ?string $phone = null): array
{
    return test()->action->execute(
        Employee::factory()->raw($overrides + ['ic_no' => test()->ic]),
        $phone ?? test()->phone,
        test()->company,
    );
}

/** The first employment, already ended — the state a rejoin continues from (BR-2). */
function priorEmployment(): array
{
    return register(['staff_status' => 'RESIGNED']);
}

function unscoped(int $id): ?Employee
{
    return Employee::withoutGlobalScope(TenantScope::class)->withTrashed()->find($id);
}

// ─── The flow that could not run ────────────────────────────────────────────────────────────

it('registers a rejoiner carrying the same IC as their prior record', function () {
    $prior = priorEmployment();

    $rejoin = register([
        'staff_status' => 'PROBATION',
        'previous_employee_id' => $prior['employee']->id,
    ]);

    expect($rejoin['employee']->ic_no)->toBe($this->ic)
        ->and($rejoin['employee']->employee_no)->toBe('AHS-0002')
        ->and($rejoin['employee']->id)->not->toBe($prior['employee']->id);
});

/**
 * ⚠ THE HALF THAT FAILED AS A 500. `users.phone_no` is NOT NULL, unique, and the login
 * username; the frozen old account keeps the row for ever (BR-A15, BR-A17, BR-A18).
 */
it('registers a rejoiner carrying the same phone number as their prior account', function () {
    $prior = priorEmployment();

    $rejoin = register([
        'staff_status' => 'PROBATION',
        'previous_employee_id' => $prior['employee']->id,
    ]);

    expect($rejoin['user']->phone_no)->toBe($this->phone)
        ->and($rejoin['user']->id)->not->toBe($prior['user']->id);
});

/**
 * ⚠ THE POINT OF THE WHOLE DESIGN. The only route through before `adr/0015` was to empty
 * `ic_no` on the old record — which destroys the identity on the historical row, and that row is
 * the one `previous_employee_id` is required to point at.
 */
it('leaves the prior record and its account still holding their own values', function () {
    $prior = priorEmployment();

    register([
        'staff_status' => 'PROBATION',
        'previous_employee_id' => $prior['employee']->id,
    ]);

    $old = unscoped($prior['employee']->id);

    expect($old->ic_no)->toBe($this->ic)
        ->and($old->user->phone_no)->toBe($this->phone)
        ->and($old->superseded_at)->not->toBeNull()
        ->and($old->user->superseded_at)->not->toBeNull();
});

it('threads the new record back to the old one through previous_employee_id', function () {
    $prior = priorEmployment();

    $rejoin = register([
        'staff_status' => 'PROBATION',
        'previous_employee_id' => $prior['employee']->id,
    ]);

    expect($rejoin['employee']->previous_employee_id)->toBe($prior['employee']->id);
});

// ─── What must NOT have been loosened ───────────────────────────────────────────────────────

/**
 * ⚠ THE CONSTRAINT THAT MUST SURVIVE. The functional index is not a relaxation: two LIVE records
 * sharing an IC is a duplicate person, which is what the unique index has always been for.
 *
 * ⚠ THIS IS ALSO WHAT THE REJECTED COMPOSITE FAILED. `UNIQUE (ic_no, superseded_at)` is created
 * successfully and passes the rejoiner tests above, then accepts two live rows here — both carry
 * NULL and NULLs are distinct. It reads as a narrowing and is a cancellation.
 */
it('still refuses two live records sharing one IC', function () {
    register();

    expect(fn () => register([], '0111112222'))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('still refuses two live accounts sharing one phone number', function () {
    register();

    // ⚠ A DIFFERENT IC, so the only thing left to collide on is the number.
    expect(fn () => test()->action->execute(
        Employee::factory()->raw(['ic_no' => '770707072222']),
        $this->phone,
        $this->company,
    ))->toThrow(UniqueConstraintViolationException::class);
});

/**
 * ⚠ THE MARK IS WHAT UNBLOCKS THE FLOW — asserted by removing it. Without the link
 * `supersedePrior()` returns immediately, nothing is released, and the insert meets the live
 * index exactly as it did before `adr/0015` existed.
 */
it('refuses a second registration that does not declare the prior record', function () {
    priorEmployment();

    expect(fn () => register(['staff_status' => 'PROBATION']))
        ->toThrow(UniqueConstraintViolationException::class);
});

// ─── Order, and why it is load-bearing ──────────────────────────────────────────────────────

/**
 * ⚠ THE REASON `supersedePrior()` IS THE FIRST STATEMENT IN THE TRANSACTION, recorded as an
 * observed failure rather than only as a comment.
 *
 * This drives the two writes directly, in the wrong order, inside one transaction. It cannot
 * reverse the order INSIDE the Action without editing it — no test can — so it asserts the
 * database fact the Action's order depends on. What catches a moved line is the end-to-end case
 * above, which goes red with this same 1062.
 */
it('is refused by the database when the insert precedes the mark', function () {
    $prior = priorEmployment();

    expect(fn () => DB::transaction(function () use ($prior) {
        DB::table('employees')->insert([
            'employee_no' => 'AHS-9999',
            'ic_no' => $this->ic,
            'full_name' => 'Out Of Order',
            'company_id' => $this->company->id,
            'department_id' => $prior['employee']->department_id,
            'nationality_id' => $prior['employee']->nationality_id,
            'date_of_birth' => '1990-01-01',
            'gender' => 'MALE',
            'level' => 'STAFF',
            'employment_type' => 'FULL-TIME',
            'staff_status' => 'PROBATION',
            'attendance_type' => 'FIXED',
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'working_days' => json_encode(['MON']),
            'offday' => json_encode(['SUN']),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Too late — the row above already competed for the value.
        DB::table('employees')->where('id', $prior['employee']->id)->update(['superseded_at' => now()]);
    }))->toThrow(UniqueConstraintViolationException::class);
});

/**
 * ⚠ WHY THE MARK IS INSIDE THE TRANSACTION RATHER THAN BEFORE IT. A registration that fails
 * halfway must not leave an old record permanently stripped of its identity claim with nobody
 * having taken it over — that would release an IC into a system where nothing holds it.
 *
 * The failure is forced with a `fingerprint_id` collision, which lands on `$employee->save()`,
 * after `supersedePrior()` has already written both marks.
 */
it('rolls the mark back when a later step in the same transaction fails', function () {
    $prior = priorEmployment();

    // A live record holding the device id the rejoin will collide with.
    test()->action->execute(
        Employee::factory()->raw(['ic_no' => '660606106666', 'fingerprint_id' => 'FP-COLLIDE']),
        '0144443322',
        $this->company,
    );

    expect(fn () => register([
        'staff_status' => 'PROBATION',
        'fingerprint_id' => 'FP-COLLIDE',
        'previous_employee_id' => $prior['employee']->id,
    ]))->toThrow(UniqueConstraintViolationException::class);

    $old = unscoped($prior['employee']->id);

    expect($old->superseded_at)->toBeNull()
        ->and($old->user->superseded_at)->toBeNull();
});

// ─── Decision 6, enforced where rows are written ────────────────────────────────────────────

/**
 * ⚠ THE GUARD THAT KEEPS DECISION 6's DATA RULE TRUE. Superseding a LIVE record releases an IC
 * and a login username while the account still works, and two live accounts could then share one
 * username — the exact failure `users.phone_no` being unique exists to prevent.
 *
 * ⚠ IT IS ALSO BR-2. A prior record still ACTIVE is not a rejoin; it is a duplicate person.
 */
it('refuses to supersede a prior record that is not terminal', function () {
    $live = register(['staff_status' => 'ACTIVE']);

    expect(fn () => register([
        'staff_status' => 'PROBATION',
        'previous_employee_id' => $live['employee']->id,
    ], '0111112222'))->toThrow(
        InvalidArgumentException::class,
        'which is not terminal, so it cannot be superseded',
    );
});

it('leaves a non-terminal prior record completely untouched when it refuses', function () {
    $live = register(['staff_status' => 'ACTIVE']);

    try {
        register([
            'staff_status' => 'PROBATION',
            'previous_employee_id' => $live['employee']->id,
        ], '0111112222');
    } catch (InvalidArgumentException) {
        // The assertion is the state below, not the exception.
    }

    $untouched = unscoped($live['employee']->id);

    expect($untouched->superseded_at)->toBeNull()
        ->and($untouched->user->superseded_at)->toBeNull()
        ->and($untouched->ic_no)->toBe($this->ic);
});

/**
 * ⚠ A LINK POINTING AT NOTHING IS REFUSED RATHER THAN IGNORED. Silently skipping the release
 * would leave the prior record still holding the IC and the number, and the insert would then
 * fail on a constraint whose cause is two layers away from its message.
 */
it('refuses a rejoiner link that matches no employee record', function () {
    expect(fn () => register([
        'staff_status' => 'PROBATION',
        'previous_employee_id' => 987654,
    ]))->toThrow(InvalidArgumentException::class, 'matches no employee record');
});

// ─── Repeat rejoins ─────────────────────────────────────────────────────────────────────────

/**
 * ⚠ AN ALREADY-SUPERSEDED RECORD KEEPS ITS ORIGINAL TIMESTAMP. Whether two records may claim one
 * predecessor is UNDECIDED — nothing makes the link unique, and `EmployeeStoreRequest` says so.
 * Overwriting would answer that question silently and destroy the date of the first supersession,
 * which is the older fact.
 */
it('does not overwrite the timestamp on a record that was already superseded', function () {
    $first = priorEmployment();

    $second = register([
        'staff_status' => 'RESIGNED',
        'previous_employee_id' => $first['employee']->id,
    ]);

    $markedAt = unscoped($first['employee']->id)->superseded_at;

    // ⚠ A DIFFERENT IC AND A DIFFERENT NUMBER, and the difference is what makes this a test of
    // the overwrite rather than of the index. The SECOND record is the live holder of the shared
    // IC — it has not been superseded by anything — so a third registration reusing that IC while
    // pointing at the FIRST record is refused, correctly, and refused before this code is
    // reached. Isolating the overwrite means giving this registration nothing to collide on.
    test()->travel(1)->second();

    register([
        'staff_status' => 'PROBATION',
        'ic_no' => '551105105511',
        'previous_employee_id' => $first['employee']->id,
    ], '0133334444');

    expect(unscoped($first['employee']->id)->superseded_at->eq($markedAt))->toBeTrue()
        ->and($second['employee']->superseded_at)->toBeNull();
});

/**
 * ⚠ TWO SUPERSEDED RECORDS MAY SHARE AN IC, and they must — somebody who leaves and returns
 * twice has three records. Every superseded row indexes to NULL, and NULLs are distinct.
 */
it('allows a third employment when two prior records already share the IC', function () {
    $first = priorEmployment();

    $second = register([
        'staff_status' => 'RESIGNED',
        'previous_employee_id' => $first['employee']->id,
    ]);

    $third = register([
        'staff_status' => 'PROBATION',
        'previous_employee_id' => $second['employee']->id,
    ]);

    expect($third['employee']->employee_no)->toBe('AHS-0003')
        ->and($third['employee']->ic_no)->toBe($this->ic)
        ->and($third['user']->phone_no)->toBe($this->phone)
        ->and(unscoped($second['employee']->id)->superseded_at)->not->toBeNull();
});
