<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PolicyConfiguration;
use App\Models\User;
use App\Services\Auth\AuthenticationService;
use Database\Seeders\MasterAdminSeeder;
use Illuminate\Support\Facades\Schema;

/**
 * ⚠ THE TEST THAT SHOULD HAVE EXISTED SINCE THE FIRST AUTH PR — adr/0006 follow-up item 4.
 *
 * The installer's account could not log in. BR-A1 makes the phone number the username,
 * adr/0001 decision 4 gives Master Admin no employee record, and the number lived on
 * `employees` — so the account had nowhere to keep its own username. With the CORRECT
 * password it was refused on the number, on its email, on its id, and on an empty string.
 *
 * It is the first account and the only one, so that was not one broken login: it was a system
 * nobody could enter, and no employee could be created until somebody did.
 *
 * **171 tests passed while that was true.** Every one of them created an account through a
 * factory, and every factory-made account had an employee record attached — so nothing ever
 * exercised the one account type that does not. That is the shape of the gap this file
 * closes: not a missing assertion, a missing SUBJECT.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);

    foreach (['auth.password.min_length' => '6', 'auth.throttle.tier_4.attempts' => '12'] as $key => $value) {
        PolicyConfiguration::create([
            'company_id' => $this->ahs->id,
            'key' => $key,
            'value' => $value,
            'effective_from' => now()->toDateString(),
        ]);
    }

    config()->set('auth.master_admin', [
        'email' => 'master@example.test',
        'phone_no' => '0193034601',
        'password' => 'installer-secret',
    ]);
});

/** The whole point, asserted end to end through the real seeder and the real service. */
it('lets the seeded Master Admin log in', function () {
    $this->seed(MasterAdminSeeder::class);

    $user = app(AuthenticationService::class)->attempt('0193034601', 'installer-secret');

    expect($user->system_access)->toBe('FULL')
        ->and($user->employee_id)->toBeNull()   // still no employee record, by design
        ->and($user->isMasterAdmin())->toBeTrue();

    $this->assertAuthenticated();
});

it('lets the seeded Master Admin log in over HTTP, in every accepted number format', function (string $typed) {
    $this->seed(MasterAdminSeeder::class);

    $this->post('/login', ['phone_no' => $typed, 'password' => 'installer-secret'])
        ->assertRedirect();

    $this->assertAuthenticated();
})->with(['0193034601', '019-303 4601', '+60193034601', '60193034601']);

/**
 * ⚠ The first thing that account must do. BR-A23 gates every route while the flag is set,
 * Master Admin included, because the seeded password came from a .env file — possibly
 * shared, possibly copied from a deployment note.
 */
it('sends the seeded Master Admin straight to the password screen', function () {
    $this->seed(MasterAdminSeeder::class);

    app(AuthenticationService::class)->attempt('0193034601', 'installer-secret');

    $this->get('/dashboard')->assertRedirect(route('password.change'));
});

/**
 * ⚠ The regression guard for the defect itself.
 *
 * If `phone_no` ever returns to `employees`, or is dropped from `users`, the Master Admin
 * account loses its username again — and the symptom is "invalid credentials", which is
 * indistinguishable from an ordinary typo.
 */
it('keeps the login username on users and off employees', function () {
    expect(Schema::hasColumn('users', 'phone_no'))->toBeTrue(
        'The login username must live on users — an account with no employee record has '.
        'nowhere else to keep one (adr/0006).'
    );

    expect(Schema::hasColumn('employees', 'phone_no'))->toBeFalse(
        'employees must not hold phone_no. Two columns for one fact eventually disagree, '.
        'and there is no separate contact number either (adr/0006 decisions 1 and 7).'
    );
});

it('refuses to seed an account with no phone number rather than creating an unreachable one', function () {
    // Producing the account silently is the defect adr/0006 closed; producing it again with
    // a friendlier code path would be the same bug wearing a hat.
    config()->set('auth.master_admin.phone_no', null);

    expect(fn () => $this->seed(MasterAdminSeeder::class))
        ->toThrow(RuntimeException::class, 'MASTER_ADMIN_PHONE');

    expect(User::query()->count())->toBe(0);
});

it('refuses a phone number that would fail BR-A1 validation', function (string $bad) {
    config()->set('auth.master_admin.phone_no', $bad);

    expect(fn () => $this->seed(MasterAdminSeeder::class))->toThrow(RuntimeException::class);

    expect(User::query()->count())->toBe(0);
})->with(['12345', 'not-a-number', '01234567890123456']);

it('stores the seeded number normalised, so every format reaches it', function () {
    config()->set('auth.master_admin.phone_no', '+6019-303 4601');

    $this->seed(MasterAdminSeeder::class);

    // BR-A1 requires ONE normaliser. A seeder that stored the raw value would create an
    // account reachable only by retyping the exact punctuation.
    expect(User::query()->sole()->phone_no)->toBe('0193034601');
});

it('stays idempotent and does not create a second Master Admin', function () {
    $this->seed(MasterAdminSeeder::class);
    $this->seed(MasterAdminSeeder::class);

    expect(User::query()->where('system_access', 'FULL')->count())->toBe(1);
});

/**
 * ⚠ The other half of adr/0006: a number belongs to exactly one account, group-wide.
 *
 * Employees no longer hold the column, so the old failure — one person's employee row and
 * another's account row sharing a number, with the login resolving to whichever table was
 * queried first — is now structurally impossible rather than merely unlikely.
 */
it('will not let two accounts share a login username', function () {
    $employee = Employee::factory()->forCompany($this->ahs)->create();
    User::factory()->forEmployee($employee)->create(['phone_no' => '0123456789']);

    $second = Employee::factory()->forCompany($this->ahs)->create();

    expect(fn () => User::factory()->forEmployee($second)->create(['phone_no' => '0123456789']))
        ->toThrow(Illuminate\Database\QueryException::class);
});
