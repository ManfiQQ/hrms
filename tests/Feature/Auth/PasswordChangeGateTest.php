<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PolicyConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/**
 * §8 test 7 — BR-A23's gate, now that HTTP routes exist to test it against.
 *
 * ⚠ "Every route except the change screen and logout" is only meaningful if more than one
 * ordinary route is tried. A gate tested against a single page passes just as happily when
 * it was applied to that page alone, which is the failure this file is shaped to catch: an
 * extra route is registered below purely so the rule has something else to block.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

    foreach ([$this->ahs, $this->aim] as $company) {
        foreach (['auth.password.min_length' => '6', 'auth.throttle.tier_4.attempts' => '12'] as $key => $value) {
            PolicyConfiguration::create([
                'company_id' => $company->id,
                'key' => $key,
                'value' => $value,
                'effective_from' => now()->toDateString(),
            ]);
        }
    }

    // A second ordinary route inside the same gated group. Without it, "every route" is a
    // claim about one page.
    Route::middleware([
        'web',
        'auth',
        App\Http\Middleware\EnsureAccountIsActive::class,
        App\Http\Middleware\EnsurePasswordIsChanged::class,
    ])->get('/_t/another-page', fn () => response('another page'))->name('t.another');
});

function gatedAccount(bool $mustChange, ?Company $company = null): User
{
    $employee = Employee::factory()->forCompany($company ?? test()->aim)->create();

    return User::factory()->forEmployee($employee)->create([
        'password' => 'secret123',
        'must_change_password' => $mustChange,
    ]);
}

it('redirects every ordinary route to the change screen while the flag is set', function (string $path) {
    $this->actingAs(gatedAccount(true));

    $this->get($path)->assertRedirect(route('password.change'));
})->with(['/dashboard', '/_t/another-page']);

it('lets the change screen and logout through', function () {
    $this->actingAs(gatedAccount(true));

    $this->get(route('password.change'))->assertOk();
    $this->post(route('logout'))->assertRedirect(route('login'));
});

/**
 * ⚠ THE ROW THE SPEC CALLS OUT BY NAME. MasterAdminSeeder creates the first account with the
 * flag set and its credentials from .env, so a Master Admin who could skip the gate would
 * keep an environment-file password as their real one indefinitely.
 */
it('blocks Master Admin exactly as it blocks everyone else', function () {
    $masterAdmin = User::factory()->masterAdmin()->create(['must_change_password' => true]);

    $this->actingAs($masterAdmin);

    $this->get('/dashboard')->assertRedirect(route('password.change'));
    $this->get('/_t/another-page')->assertRedirect(route('password.change'));

    // And the escape hatches still work for them too.
    $this->get(route('password.change'))->assertOk();
});

it('lets an account through once the flag is cleared', function () {
    $this->actingAs(gatedAccount(false));

    $this->get('/dashboard')->assertOk();
    $this->get('/_t/another-page')->assertOk();
});

it('clears the flag, stamps the change, and rotates the session on success', function () {
    $user = gatedAccount(true);
    $this->actingAs($user);

    $this->post(route('password.change.update'), [
        'password' => 'a memorable phrase',
        'password_confirmation' => 'a memorable phrase',
    ])->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and($user->password_changed_at)->not->toBeNull()
        ->and(Hash::check('a memorable phrase', $user->password))->toBeTrue();

    // The gate lets them through afterwards, which is the only proof that matters.
    $this->get('/dashboard')->assertOk();
});

/** BR-A2 — the minimum comes from policy_configurations, and there are no composition rules. */
it('enforces the configured minimum length and nothing else', function () {
    $user = gatedAccount(true);
    $this->actingAs($user);

    $this->from(route('password.change'))->post(route('password.change.update'), [
        'password' => '12345',
        'password_confirmation' => '12345',
    ])->assertSessionHasErrors('password');

    // Six characters, all lowercase, no digits or symbols: accepted deliberately. Complexity
    // rules produce Abcd1234! and passwords written on paper.
    $this->post(route('password.change.update'), [
        'password' => 'sixchr',
        'password_confirmation' => 'sixchr',
    ])->assertRedirect(route('dashboard'));

    expect($user->fresh()->must_change_password)->toBeFalse();
});

it('rejects a mismatched confirmation', function () {
    $this->actingAs(gatedAccount(true));

    $this->from(route('password.change'))->post(route('password.change.update'), [
        'password' => 'a memorable phrase',
        'password_confirmation' => 'a different phrase',
    ])->assertSessionHasErrors('password');
});

it('does not ask for the current password', function () {
    // Reached after an HR reset and after a QR activation, where the employee has never had
    // one. Requiring it would make the second path impossible.
    $this->actingAs(gatedAccount(true));

    $this->get(route('password.change'))
        ->assertOk()
        ->assertDontSee('current password', false)
        ->assertDontSee('name="current_password"', false);
});
