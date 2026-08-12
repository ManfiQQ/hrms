<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PolicyConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The account state gate — auth-rbac.spec.md §5.2.
 *
 * ⚠ Freeze is enforced HERE, not in each policy. A policy-by-policy freeze check is the one
 * that gets forgotten in the twentieth policy, and the omission returns a successful write
 * rather than an error — the account keeps working and nobody finds out.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

    foreach ([$this->ahs, $this->aim] as $company) {
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

function accountWithStatus(string $status, Company $company): User
{
    $employee = Employee::factory()->forCompany($company)->create(['staff_status' => $status]);

    return User::factory()->forEmployee($employee)->create();
}

it('lets an active account read and write', function () {
    $this->actingAs(accountWithStatus('CONFIRMED', $this->aim));

    $this->get('/_t/read')->assertOk();
    $this->post('/_t/write')->assertOk();
});

/**
 * ⚠ Both halves in one test. A frozen account keeps READ access to its own data during the
 * window — BR-A15 is a freeze, not a lockout — so asserting only the refusal would pass
 * against a middleware that logged them out entirely, which is a different rule.
 */
it('freezes a resigned account: reads still work, writes are refused', function (string $status) {
    $this->actingAs(accountWithStatus($status, $this->aim));

    $this->get('/_t/read')->assertOk();
    $this->post('/_t/write')->assertForbidden();
})->with(['RESIGNED', 'TERMINATED']);

it('does not freeze a suspended account', function () {
    // SUSPENDED is not terminal (BR-A2): the employment continues, so the account does not
    // freeze. Treating it as terminal would lock out someone who is coming back.
    $this->actingAs(accountWithStatus('SUSPENDED', $this->aim));

    $this->post('/_t/write')->assertOk();
});

/**
 * ⚠ A permanently locked account is logged out even mid-session. HR may lock it, or the
 * twelfth failure may land from another device, while a session sits open on a shared
 * terminal — and the session must not outlive the lock.
 */
it('refuses an account that has been locked permanently since its session began', function () {
    $user = accountWithStatus('CONFIRMED', $this->aim);
    $this->actingAs($user);

    $this->get('/_t/read')->assertOk();

    User::query()->whereKey($user->id)->update(['failed_login_attempts' => 12]);

    // ⚠ Re-acting with the fresh row on purpose. The session guard reloads the user from the
    // database on every request in production, so a lock applied mid-session is seen on the
    // next one; actingAs() pins one in-memory instance for the whole test, which would hide
    // that. The rule under assertion is the gate's, not the guard's.
    $this->actingAs($user->fresh());

    $this->get('/_t/read')->assertForbidden();
});

it('lets a Master Admin through, having no employee record to freeze', function () {
    // Master Admin has a null employee_id (adr/0004 decision 4), so there is no staff_status
    // to read. The gate must not treat "no employee" as "no status, therefore frozen".
    $this->actingAs(User::factory()->masterAdmin()->create());

    $this->post('/_t/write')->assertOk();
});

it('ignores unauthenticated requests', function () {
    // Route middleware is what protects unauthenticated access; this gate is about the state
    // of an account that has already been identified.
    $this->get('/_t/read')->assertOk();
});

