<?php

use App\Actions\Employee\CreateEmployee;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PolicyConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * `adr/0015` decision 6 — no `users` row may carry `superseded_at` while its employee holds a
 * non-terminal `staff_status`.
 *
 * ⚠ WHY THIS IS THE INVARIANT WORTH GUARDING. `superseded_at` releases a row's claim on the
 * unique index, so a superseded row whose account still logs in has released a LOGIN USERNAME
 * that is still in use — and two live accounts could then hold one number. That is precisely the
 * failure `users.phone_no` being unique exists to prevent, and it would happen with nothing
 * erroring anywhere.
 *
 * ⚠ THE GUARD IS OVER THE DATA, AND IT IS NOT THE ONLY ENFORCEMENT. `CreateEmployee` refuses to
 * supersede a non-terminal record at the point of write (RejoinerIdentityTest covers that). Both
 * are needed and they are not duplicates: the Action stops the state being created through the
 * front door, this asserts no other path has created it. `conventions.md` §9 is explicit that a
 * guard asserting something the code cannot produce documents a failure rather than preventing
 * one — so the second test below proves this detector can still see a violation.
 */
beforeEach(function () {
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
});

/**
 * Every account carrying `superseded_at` whose employee is NOT terminal.
 *
 * ⚠ JOINED RATHER THAN READ THROUGH THE MODEL, and unscoped by construction. A superseded record
 * is soft-deleted as often as not and may belong to a former employer, so an Eloquent read under
 * `TenantScope` or without `withTrashed()` would return an empty set and report a clean system.
 */
function livingAccountsMarkedSuperseded(): array
{
    return DB::table('users')
        ->join('employees', 'employees.id', '=', 'users.employee_id')
        ->whereNotNull('users.superseded_at')
        ->whereNotIn('employees.staff_status', Employee::TERMINAL_STATUSES)
        ->pluck('users.phone_no')
        ->all();
}

/**
 * The realistic sequence, end to end: somebody is employed, resigns, and returns. Every write
 * goes through the Action, which is the only writer of this column.
 */
it('holds the invariant across a full resign-and-rejoin cycle', function () {
    $first = $this->action->execute(
        Employee::factory()->raw(['ic_no' => '900101145501', 'staff_status' => 'RESIGNED']),
        '0198887766',
        $this->company,
    );

    $rejoin = $this->action->execute(
        Employee::factory()->raw([
            'ic_no' => '900101145501',
            'staff_status' => 'PROBATION',
            'previous_employee_id' => $first['employee']->id,
        ]),
        '0198887766',
        $this->company,
    );

    // The prior account IS superseded, and its employee IS terminal — the permitted combination.
    expect($first['user']->fresh()->superseded_at)->not->toBeNull()
        ->and($rejoin['user']->fresh()->superseded_at)->toBeNull()
        ->and(livingAccountsMarkedSuperseded())->toBe([]);
});

/**
 * ⚠ THE DETECTOR IS PROVED AGAINST A REAL VIOLATION, and this test is the reason the one above
 * can be trusted. The forbidden state is forced in through the query builder — which bypasses
 * model events entirely (`conventions.md` §9), so it is also the shape any future path that
 * skipped the Action would take.
 *
 * Without this, `livingAccountsMarkedSuperseded()` returning `[]` proves nothing: an always-empty
 * query and a genuinely clean table are indistinguishable.
 */
it('detects an account superseded while its employee is still active', function () {
    $employed = $this->action->execute(
        Employee::factory()->raw(['ic_no' => '880202101234', 'staff_status' => 'ACTIVE']),
        '0177776655',
        $this->company,
    );

    expect(livingAccountsMarkedSuperseded())->toBe([]);

    DB::table('users')
        ->where('id', $employed['user']->id)
        ->update(['superseded_at' => now()]);

    expect(livingAccountsMarkedSuperseded())->toBe(['0177776655']);
});
