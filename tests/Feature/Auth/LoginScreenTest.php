<?php

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PolicyConfiguration;
use App\Models\SecurityEvent;
use App\Models\User;

/**
 * The login screen over real HTTP — auth-rbac.spec.md §5.1, §7.
 *
 * The service is tested in AuthenticationServiceTest; this file is about the parts only a
 * request can show: that the controller stays thin, that the form carries no remember-me
 * field, and that the failure message reaching the page discloses nothing.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

    foreach ([$this->ahs, $this->aim] as $company) {
        foreach ([
            'auth.password.min_length' => '6',
            'auth.throttle.tier_1.attempts' => '3',
            'auth.throttle.tier_1.lock_minutes' => '5',
            'auth.throttle.tier_2.attempts' => '6',
            'auth.throttle.tier_2.lock_minutes' => '10',
            'auth.throttle.tier_3.attempts' => '9',
            'auth.throttle.tier_3.lock_minutes' => '15',
            'auth.throttle.tier_4.attempts' => '12',
        ] as $key => $value) {
            PolicyConfiguration::create([
                'company_id' => $company->id,
                'key' => $key,
                'value' => $value,
                'effective_from' => now()->toDateString(),
            ]);
        }
    }
});

function loginAccount(string $phone = '0123456789', array $attributes = []): User
{
    $employee = Employee::factory()->forCompany(test()->aim)->create();

    return User::factory()->forEmployee($employee)->create(array_merge([
        'phone_no' => $phone,
        'password' => 'secret123',
        'must_change_password' => false,
    ], $attributes));
}

it('shows the login form to a guest', function () {
    $this->get('/login')->assertOk()->assertSee('Phone number');
});

it('sends the root path to the login screen', function () {
    $this->get('/')->assertRedirect('/login');
});

/**
 * ⚠ §8 test 8, at the form level. The service has no $remember parameter, and the form must
 * not offer one either — a checkbox here would read as a feature that exists, which is how
 * it gets wired up.
 */
it('offers no remember-me field and asks for a phone number, not an email', function () {
    $response = $this->get('/login');

    $response->assertDontSee('remember', false)
        ->assertDontSee('type="email"', false)
        ->assertSee('name="phone_no"', false);
});

it('logs a valid account in and lands it on the dashboard', function () {
    loginAccount();

    $this->post('/login', ['phone_no' => '012-345 6789', 'password' => 'secret123'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();
});

/**
 * ⚠ The page must say the same thing whether or not the number belongs to anyone. The
 * username is a phone number, so an existence oracle here is worth more than it looks.
 */
it('shows one identical message for an unknown number and a wrong password', function () {
    loginAccount();

    $unknown = $this->from('/login')
        ->post('/login', ['phone_no' => '0119999999', 'password' => 'secret123']);

    $wrong = $this->from('/login')
        ->post('/login', ['phone_no' => '0123456789', 'password' => 'not-the-password']);

    $unknown->assertSessionHasErrors(['phone_no' => InvalidCredentialsException::MESSAGE]);
    $wrong->assertSessionHasErrors(['phone_no' => InvalidCredentialsException::MESSAGE]);

    $this->assertGuest();
});

it('says the same thing again once the account is locked', function () {
    $user = loginAccount();
    User::query()->whereKey($user->id)->update([
        'failed_login_attempts' => 3,
        'locked_until' => now()->addMinutes(5),
    ]);

    // Correct password, locked account: still the generic message, and still no session.
    $this->from('/login')
        ->post('/login', ['phone_no' => '0123456789', 'password' => 'secret123'])
        ->assertSessionHasErrors(['phone_no' => InvalidCredentialsException::MESSAGE]);

    $this->assertGuest();
});

it('does not mint a remember cookie even when remember is posted directly', function () {
    loginAccount();

    // ⚠ §8 test 8. Removing the checkbox is not the same as disabling the feature — this is
    // the request that proves the difference.
    $response = $this->post('/login', [
        'phone_no' => '0123456789',
        'password' => 'secret123',
        'remember' => 1,
        'remember_me' => 'on',
    ]);

    $recallers = collect($response->headers->getCookies())
        ->filter(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_'));

    expect($recallers)->toBeEmpty();
    $this->assertAuthenticated();
});

it('writes the successful login to security_events', function () {
    loginAccount();

    $this->post('/login', ['phone_no' => '0123456789', 'password' => 'secret123']);

    expect(SecurityEvent::query()->where('event_type', 'LOGIN_SUCCESS')->count())->toBe(1);
});

it('logs out on POST only, and clears the session', function () {
    $user = loginAccount();
    $this->actingAs($user);

    $this->get('/logout')->assertMethodNotAllowed();

    $this->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('keeps a guest out of the dashboard', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});

it('sends a signed-in visitor away from the login form', function () {
    $this->actingAs(loginAccount());

    $this->get('/login')->assertRedirect();
});
