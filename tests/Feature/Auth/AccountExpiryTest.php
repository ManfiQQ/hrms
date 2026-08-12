<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\PolicyConfiguration;
use App\Models\User;
use App\Services\Auth\AccountExpiry;
use Illuminate\Support\Facades\Route;

/**
 * BR-A17 — §8 test 24, and the boundary either side of it.
 *
 * ⚠ THE DATE THIS COUNTS FROM IS THE WHOLE RULE. Ten days from `effective_date`, the last
 * working day — never from the day HR typed the change. A resignation entered three weeks
 * late would otherwise hand the person three extra weeks of access, and one entered early
 * would cut them off while they were still working. Both dates sit on the same ledger row,
 * so getting this wrong is a one-word mistake with no symptom until somebody checks.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->tursenia = Company::factory()->subsidiary($this->ahs)->create(['code' => 'TURSENIA']);

    foreach ([$this->ahs, $this->aim, $this->tursenia] as $company) {
        foreach (['auth.throttle.tier_4.attempts' => '12', 'auth.account.expiry_days' => '10'] as $key => $value) {
            PolicyConfiguration::create([
                'company_id' => $company->id,
                'key' => $key,
                'value' => $value,
                'effective_from' => now()->toDateString(),
            ]);
        }
    }

    Route::middleware(['web', EnsureAccountIsActive::class])->group(function () {
        Route::get('/_t/read', fn () => response('read ok'));
        Route::post('/_t/write', fn () => response('write ok'));
    });
});

/**
 * An employee who left on $lastWorkingDay, with the ledger row that records it.
 */
function departedOn(string $lastWorkingDay, string $status = 'RESIGNED', ?Company $company = null): User
{
    $company ??= test()->aim;

    $employee = Employee::factory()->forCompany($company)->create(['staff_status' => $status]);

    EmployeeStatusHistory::factory()
        ->forEmployee($employee)
        ->ofType('STAFF_STATUS')
        ->effectiveOn($lastWorkingDay)
        ->create(['old_value' => 'CONFIRMED', 'new_value' => $status, 'new_label' => $status]);

    return User::factory()->forEmployee($employee)->create();
}

/**
 * ⚠ THE BOUNDARY, ASSERTED ON BOTH SIDES AND ON THE EDGE ITSELF.
 *
 * An off-by-one here either cuts somebody off a day early — while they are still entitled to
 * fetch their own confirmation letter — or leaves an ex-employee reading records a day
 * longer than the rule allows. Neither raises anything; both are simply wrong on one day.
 *
 * The window is INCLUSIVE of its last day: a last working day of the 1st means the account
 * works through the 11th and is gone on the 12th. Ten whole days after the employment ended,
 * which is what "ten days after" means to the person living it.
 */
it('lets a departed employee in on day nine', function () {
    $user = departedOn(now()->subDays(9)->toDateString());

    $this->actingAs($user)->get('/_t/read')->assertOk();
});

it('lets a departed employee in on the last day of the window', function () {
    $user = departedOn(now()->subDays(10)->toDateString());

    $this->actingAs($user)->get('/_t/read')->assertOk();
});

it('shuts a departed employee out on day eleven', function () {
    $user = departedOn(now()->subDays(11)->toDateString());

    $this->actingAs($user)->get('/_t/read')->assertForbidden();
});

it('shuts them out long afterwards too', function () {
    $user = departedOn(now()->subDays(400)->toDateString());

    $this->actingAs($user)->get('/_t/read')->assertForbidden();
});

/**
 * ⚠ §8 test 24 — counted from `effective_date`, NOT from the date the status was set.
 *
 * The row is created today and back-dated: HR entering a resignation three weeks after the
 * fact must not extend the window by three weeks. This is the assertion the rule exists for,
 * and it passes against a correct implementation and fails against the obvious wrong one.
 */
it('counts from the last working day, not from when HR typed it', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create(['staff_status' => 'RESIGNED']);

    // Typed NOW, effective three weeks ago.
    EmployeeStatusHistory::factory()
        ->forEmployee($employee)
        ->ofType('STAFF_STATUS')
        ->effectiveOn(now()->subDays(21)->toDateString())
        ->create(['new_value' => 'RESIGNED', 'new_label' => 'RESIGNED']);

    $user = User::factory()->forEmployee($employee)->create();

    // created_at is today; effective_date is 21 days ago. An implementation reading
    // created_at would let this account straight in.
    expect($employee->statusHistory()->sole()->created_at->toDateString())->toBe(now()->toDateString());

    $this->actingAs($user)->get('/_t/read')->assertForbidden();
});

it('applies to TERMINATED exactly as to RESIGNED', function () {
    $user = departedOn(now()->subDays(11)->toDateString(), 'TERMINATED');

    $this->actingAs($user)->get('/_t/read')->assertForbidden();
});

/** Expiry is stricter than the freeze, so it must be reached first. */
it('shuts an expired account out of reads, not merely out of writes', function () {
    $user = departedOn(now()->subDays(11)->toDateString());

    // A frozen account still reads its own data. An expired one does not — if the freeze
    // check were reached first, this would return 200 and the countdown would never end
    // anything.
    $this->actingAs($user)->get('/_t/read')->assertForbidden();
    $this->actingAs($user)->post('/_t/write')->assertForbidden();
});

it('leaves an employee still working entirely alone', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create(['staff_status' => 'CONFIRMED']);

    // A promotion ten years ago must not expire anybody — only a TERMINAL status starts the
    // countdown, not any ledger row.
    EmployeeStatusHistory::factory()
        ->forEmployee($employee)
        ->ofType('LEVEL')
        ->effectiveOn(now()->subYears(10)->toDateString())
        ->create(['new_value' => 'MANAGER', 'new_label' => 'Manager']);

    $this->actingAs(User::factory()->forEmployee($employee)->create());

    $this->get('/_t/read')->assertOk();
    $this->post('/_t/write')->assertOk();
});

it('never expires Master Admin, which has no employee record to depart from', function () {
    $this->actingAs(User::factory()->masterAdmin()->create());

    $this->travel(400)->days();

    $this->get('/_t/read')->assertOk();
});

/**
 * ⚠ A correction is a new row, because the ledger is append-only. The LATEST terminal row
 * must win, or a status set in error and corrected would keep counting from the mistake.
 */
it('counts from the most recent terminal row when there is more than one', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create(['staff_status' => 'TERMINATED']);

    // Entered wrongly as of a month ago, corrected to yesterday.
    EmployeeStatusHistory::factory()->forEmployee($employee)->ofType('STAFF_STATUS')
        ->effectiveOn(now()->subDays(30)->toDateString())
        ->create(['new_value' => 'RESIGNED', 'new_label' => 'RESIGNED']);

    EmployeeStatusHistory::factory()->forEmployee($employee)->ofType('STAFF_STATUS')
        ->effectiveOn(now()->subDay()->toDateString())
        ->create(['new_value' => 'TERMINATED', 'new_label' => 'TERMINATED']);

    $this->actingAs(User::factory()->forEmployee($employee)->create());

    // Counting from the older row would have shut this account out ten days ago.
    $this->get('/_t/read')->assertOk();
});

/**
 * ⚠ The countdown must survive a company transfer, and this is the case the §2 carve-out
 * exists for.
 *
 * The terminal row is frozen under the FORMER employer, so a tenant-scoped read would miss
 * it entirely and report the account as never expiring — access wider than the rule allows,
 * with nothing to notice.
 */
it('finds a terminal row frozen under a previous employer', function () {
    $employee = Employee::factory()->forCompany($this->tursenia)->create(['staff_status' => 'RESIGNED']);

    EmployeeStatusHistory::factory()
        ->forEmployee($employee)
        ->frozenUnder($this->aim)          // the company they left, not the one they are in
        ->ofType('STAFF_STATUS')
        ->effectiveOn(now()->subDays(11)->toDateString())
        ->create(['new_value' => 'RESIGNED', 'new_label' => 'RESIGNED']);

    $this->actingAs(User::factory()->forEmployee($employee)->create());

    $this->get('/_t/read')->assertForbidden();
});

/**
 * ⚠ A KNOWN STATE, ASSERTED SO IT IS NOT A SURPRISE.
 *
 * A terminal status with NO ledger row behind it never expires — there is nothing to count
 * from, so the account stays frozen and readable instead. That is WIDER than BR-A17 allows,
 * and it is a real dependency rather than an oversight: employee-master.spec.md §5.3 makes
 * the status-change service write the ledger row in the same transaction as the change, so
 * the two cannot come apart. Until that Action exists, no terminal status can be set through
 * the application at all.
 */
it('cannot expire a terminal status that was never written to the ledger', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create(['staff_status' => 'RESIGNED']);
    $user = User::factory()->forEmployee($employee)->create();

    expect(app(AccountExpiry::class)->expiresAfter($user))->toBeNull();

    $this->actingAs($user);

    // Frozen, not expired: reads work, writes do not.
    $this->get('/_t/read')->assertOk();
    $this->post('/_t/write')->assertForbidden();
});

/** The window is configuration, not a literal (conventions.md §5). */
it('reads the window from policy_configurations', function () {
    PolicyConfiguration::query()
        ->where('key', 'auth.account.expiry_days')
        ->update(['value' => '30']);

    $user = departedOn(now()->subDays(11)->toDateString());

    // Eleven days out, but the window is now thirty.
    $this->actingAs($user)->get('/_t/read')->assertOk();
});

it('refuses to guess the window when it is not configured', function () {
    PolicyConfiguration::query()->where('key', 'auth.account.expiry_days')->forceDelete();

    $user = departedOn(now()->subDays(11)->toDateString());

    // A default compiled into the code would be a second source for the number, and the two
    // would disagree the first time the table changed — with the code's copy winning silently.
    expect(fn () => app(AccountExpiry::class)->hasExpired($user))->toThrow(RuntimeException::class);
});

it('reports the last working day the account still has', function () {
    // BR-A19's five-dashboard countdown is UI work and not built here, but it needs this
    // date, so the service exposes it rather than making the UI recompute the rule.
    $user = departedOn(now()->subDays(3)->toDateString());

    expect(app(AccountExpiry::class)->expiresAfter($user)->toDateString())
        ->toBe(now()->addDays(7)->toDateString());
});
