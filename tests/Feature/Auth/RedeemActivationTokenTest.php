<?php

use App\Actions\Auth\RedeemActivationToken;
use App\Events\Auth\AccountActivated;
use App\Exceptions\Auth\InvalidActivationTokenException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PolicyConfiguration;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * BR-A21 — redeeming the single-use activation QR, auth-rbac.spec.md §5.6.
 *
 * ⚠ This is the highest-value unauthenticated endpoint in the system. Redemption
 * authenticates the holder outright and lets them set the account's first password, because
 * the account was created with no usable one (adr/0004 decision 7). Whoever holds a live
 * token becomes that employee — and everything they then do is attributed to them.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

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

    $this->redeem = app(RedeemActivationToken::class);
});

function pendingAccount(array $overrides = []): User
{
    $employee = Employee::factory()->forCompany(test()->aim)->create();

    return User::factory()->forEmployee($employee)->create(array_merge([
        'phone_no' => '0123456789',
        'activation_token' => 'a-live-token',
        'activation_expires_at' => now()->addHours(48),
        'activation_used_at' => null,
        'activation_downloaded_at' => null,
        'must_change_password' => true,
    ], $overrides));
}

it('authenticates the holder and kills the token', function () {
    $user = pendingAccount();

    $redeemed = $this->redeem->execute('a-live-token');

    expect($redeemed->id)->toBe($user->id)
        ->and($user->fresh()->activation_used_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
});

/**
 * ⚠ BR-A23 takes over from here. The account still has no password the employee chose, so
 * the gate must force one before anything else — this Action deliberately does not redirect
 * or clear the flag itself.
 */
it('leaves the password gate closed, so the employee must set one', function () {
    pendingAccount();

    $user = $this->redeem->execute('a-live-token');

    expect($user->must_change_password)->toBeTrue();

    $this->get('/dashboard')->assertRedirect(route('password.change'));
});

/**
 * ⚠ ONE MESSAGE FOR ALL THREE CAUSES. Telling somebody a token EXPIRED confirms it once
 * existed; telling them it is UNKNOWN confirms it never did. Either turns the activation URL
 * into an oracle, and the reward for finding a live one is an account rather than a hint.
 */
it('gives one identical message for used, expired and unknown tokens', function () {
    pendingAccount(['activation_token' => 'used-token', 'activation_used_at' => now()->subDay()]);

    $employee = Employee::factory()->forCompany($this->aim)->create();
    User::factory()->forEmployee($employee)->create([
        'phone_no' => '0111111111',
        'activation_token' => 'expired-token',
        'activation_expires_at' => now()->subHour(),
        'activation_used_at' => null,
    ]);

    $messages = [];

    foreach (['used-token', 'expired-token', 'never-existed'] as $token) {
        try {
            $this->redeem->execute($token);
        } catch (InvalidActivationTokenException $e) {
            $messages[] = $e->getMessage();
        }
    }

    expect($messages)->toHaveCount(3)
        ->and(array_unique($messages))->toHaveCount(1)
        ->and($messages[0])->toBe(InvalidActivationTokenException::MESSAGE);
});

it('refuses a token that has already been redeemed', function () {
    $user = pendingAccount(['activation_used_at' => now()->subMinute()]);

    expect(fn () => $this->redeem->execute('a-live-token'))
        ->toThrow(InvalidActivationTokenException::class);

    $this->assertGuest();
});

it('refuses a token past its validity window', function () {
    pendingAccount(['activation_expires_at' => now()->subSecond()]);

    // 48 hours, then HR regenerates. A QR forwarded over WhatsApp can be forwarded again,
    // so the window is what limits how long that matters (BR-A21).
    expect(fn () => $this->redeem->execute('a-live-token'))
        ->toThrow(InvalidActivationTokenException::class);

    $this->assertGuest();
});

it('refuses an account whose token was never issued', function () {
    pendingAccount(['activation_token' => null, 'activation_expires_at' => null]);

    expect(fn () => $this->redeem->execute(''))
        ->toThrow(InvalidActivationTokenException::class);
});

/**
 * ⚠ The moment an account changes hands, recorded even though it succeeded. That is what
 * makes it a security event rather than an audit row.
 */
it('writes the redemption to security_events', function () {
    $user = pendingAccount();

    $this->redeem->execute('a-live-token');

    $event = SecurityEvent::query()->sole();

    expect($event->event_type)->toBe('ACTIVATION_REDEEMED')
        ->and($event->user_id)->toBe($user->id)
        ->and($event->identifier)->toBe('0123456789');
});

it('still activates when security_events cannot be written', function () {
    pendingAccount();

    // BR-AT8: that write is non-blocking. A broken table must not stop an employee getting
    // into the system on their first day.
    Illuminate\Support\Facades\Schema::rename('security_events', 'security_events_missing');

    try {
        $user = $this->redeem->execute('a-live-token');
        expect($user->activation_used_at)->not->toBeNull();
    } finally {
        Illuminate\Support\Facades\Schema::rename('security_events_missing', 'security_events');
    }
});

it('emits the HR notification event, which nothing listens to yet', function () {
    Event::fake([AccountActivated::class]);

    $user = pendingAccount();

    $this->redeem->execute('a-live-token');

    // §5.6 requires HR to be notified; the Notification Engine has no spec, so the trigger
    // is here and the delivery is not.
    Event::assertDispatched(AccountActivated::class, fn (AccountActivated $e) => $e->user->is($user));
});

/**
 * ⚠ BR-A22 — the system records what it can OBSERVE. Serving the QR image sets
 * activation_downloaded_at, and no image exists yet, so redemption must not set it.
 * Stamping it here would assert that HR fetched an image that was never generated.
 */
it('does not touch activation_downloaded_at', function () {
    pendingAccount();

    $user = $this->redeem->execute('a-live-token');

    expect($user->fresh()->activation_downloaded_at)->toBeNull();
});

it('redeems over HTTP and lands on the password screen', function () {
    pendingAccount();

    $this->get('/activate/a-live-token')->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    // And the gate immediately sends them to set a password.
    $this->get('/dashboard')->assertRedirect(route('password.change'));
});

it('sends a bad token back to login with the generic message', function () {
    $this->get('/activate/never-existed')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['phone_no' => InvalidActivationTokenException::MESSAGE]);

    $this->assertGuest();
});

/**
 * ⚠ Much of this workforce activates on a SHARED TERMINAL at the factory, studio or
 * galleria. A scan arriving while somebody else is signed in must replace that session, not
 * inherit it and not be refused.
 */
it('replaces whoever was already signed in on the terminal', function () {
    $previousEmployee = Employee::factory()->forCompany($this->aim)->create();
    $previous = User::factory()->forEmployee($previousEmployee)->create([
        'phone_no' => '0119999999',
        'must_change_password' => false,
    ]);

    $this->actingAs($previous);

    $newUser = pendingAccount();

    $this->get('/activate/a-live-token')->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($newUser->fresh());
});
