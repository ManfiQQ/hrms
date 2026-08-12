<?php

use App\Exceptions\Auth\AccountLockedException;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PolicyConfiguration;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\LoginThrottle;
use App\Support\Auth\PhoneNumber;
use Illuminate\Support\Facades\Auth;

/**
 * Login — auth-rbac.spec.md §5.1, BR-A1 to BR-A5, §8 tests 1-8.
 *
 * ⚠ The throttle tiers are load-bearing, not defence in depth. The username is a phone
 * number known to colleagues and the password minimum is six characters, chosen by the
 * client over the recommended eight. Password strength is not carrying the security here;
 * the tests below are what prove the thing that is.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

    seedAuthPolicies($this->ahs);
    seedAuthPolicies($this->aim);

    $this->service = app(AuthenticationService::class);
});

function seedAuthPolicies(Company $company): void
{
    $values = [
        'auth.password.min_length' => '6',
        'auth.throttle.tier_1.attempts' => '3',
        'auth.throttle.tier_1.lock_minutes' => '5',
        'auth.throttle.tier_2.attempts' => '6',
        'auth.throttle.tier_2.lock_minutes' => '10',
        'auth.throttle.tier_3.attempts' => '9',
        'auth.throttle.tier_3.lock_minutes' => '15',
        'auth.throttle.tier_4.attempts' => '12',
        'auth.session.inactivity_minutes' => '120',
    ];

    foreach ($values as $key => $value) {
        PolicyConfiguration::create([
            'company_id' => $company->id,
            'key' => $key,
            'value' => $value,
            'effective_from' => now()->toDateString(),
        ]);
    }
}

function accountWithPhone(string $phone, Company $company, string $password = 'secret123'): User
{
    $employee = Employee::factory()->forCompany($company)->create(['phone_no' => $phone]);

    return User::factory()->forEmployee($employee)->create(['password' => $password]);
}

/** §8 test 1 — all three formats are one number. */
it('authenticates the same account from every accepted phone format', function (string $typed) {
    $user = accountWithPhone('0123456789', $this->aim);

    expect($this->service->attempt($typed, 'secret123')->id)->toBe($user->id);
})->with([
    '0123456789',
    '012-345 6789',
    '+60123456789',
    '60123456789',
    '  012 345 6789  ',
]);

/**
 * ⚠ The prefix is stripped only when it LEADS. 0136012345 contains "60" in the middle, and
 * removing it there would rewrite one employee's number into another's.
 */
it('strips the country prefix only from the front', function () {
    expect(PhoneNumber::normalise('0136012345'))->toBe('0136012345')
        ->and(PhoneNumber::normalise('+60136012345'))->toBe('0136012345');
});

/** §8 test 2 — BR-A1's length rule, applied to the normalised value. */
it('rejects fewer than 9 or more than 12 digits', function (string $input) {
    accountWithPhone('0123456789', $this->aim);

    expect(fn () => $this->service->attempt($input, 'secret123'))
        ->toThrow(InvalidCredentialsException::class);
})->with(['01234567', '0123456789012345', '1', '']);

/**
 * §8 test 5 — the response reveals nothing about whether the username exists.
 *
 * ⚠ The username IS a phone number, so an oracle here turns "I know this person works there"
 * into "I know their login", and a hundred numbers can be probed in a minute.
 */
it('gives one identical message for an unknown number, a wrong password and a locked account', function () {
    accountWithPhone('0123456789', $this->aim);

    $messages = [];

    try {
        $this->service->attempt('0119999999', 'secret123');   // no such account
    } catch (InvalidCredentialsException $e) {
        $messages[] = $e->getMessage();
    }

    try {
        $this->service->attempt('0123456789', 'wrong-password');
    } catch (InvalidCredentialsException $e) {
        $messages[] = $e->getMessage();
    }

    try {
        $this->service->attempt('012345678', 'secret123');    // 9 digits, malformed for us
    } catch (InvalidCredentialsException $e) {
        $messages[] = $e->getMessage();
    }

    expect(array_unique($messages))->toHaveCount(1)
        ->and($messages[0])->toBe(InvalidCredentialsException::MESSAGE);
});

it('gives a locked account the same message as any other failure', function () {
    $user = accountWithPhone('0123456789', $this->aim);
    $user->forceFill(['failed_login_attempts' => 3, 'locked_until' => now()->addMinutes(5)])->save();

    // Even with the CORRECT password: the lock is checked before the password (§5.1 step 2).
    try {
        $this->service->attempt('0123456789', 'secret123');
        $this->fail('a locked account must not authenticate');
    } catch (AccountLockedException $e) {
        expect($e->getMessage())->toBe(InvalidCredentialsException::MESSAGE)
            ->and($e->lockedUntil)->not->toBeNull();
    }
});

/**
 * §8 test 3 — the tiers fire at 3, 6, 9 with 5, 10 and 15 minute locks.
 *
 * Driven through LoginThrottle rather than through repeated attempt() calls, because a
 * locked account fails BEFORE the password check (§5.1 step 2) and so cannot deliver the
 * next failure — the lock working correctly is what makes the loop impossible.
 */
it('locks for five, ten and fifteen minutes at the first three tiers', function (int $attempts, int $minutes) {
    $user = accountWithPhone('0123456789', $this->aim);
    User::query()->whereKey($user->id)->update(['failed_login_attempts' => $attempts - 1]);

    app(LoginThrottle::class)->recordFailure($user->fresh());

    $user = $user->fresh();

    expect($user->failed_login_attempts)->toBe($attempts)
        ->and($user->locked_until)->not->toBeNull()
        ->and($user->locked_until->diffInMinutes(now(), absolute: true))->toBeLessThanOrEqual($minutes)
        ->and($user->locked_until->isFuture())->toBeTrue();
})->with([
    'tier 1: 3 failures → 5 minutes' => [3, 5],
    'tier 2: 6 failures → 10 minutes' => [6, 10],
    'tier 3: 9 failures → 15 minutes' => [9, 15],
]);

it('applies the highest tier reached, not the first one crossed', function () {
    // At six failures the lock is ten minutes, not the five that tier 1 would give — a loop
    // that stopped at the first matching threshold would silently under-lock every account
    // past the first tier.
    $user = accountWithPhone('0123456789', $this->aim);
    User::query()->whereKey($user->id)->update(['failed_login_attempts' => 5]);

    app(LoginThrottle::class)->recordFailure($user->fresh());

    expect($user->fresh()->locked_until->diffInMinutes(now(), absolute: true))->toBeGreaterThan(5);
});

it('locks permanently at the fourth tier, and a correct password does not lift it', function () {
    $user = accountWithPhone('0123456789', $this->aim);
    $user->forceFill(['failed_login_attempts' => 12])->save();

    expect(app(LoginThrottle::class)->isPermanentlyLocked($user))->toBeTrue();

    // ⚠ No automatic expiry and no self-service path: at twelve failures the likeliest
    // explanation is no longer a typo. Only HR or Master Admin lifts it (BR-A7).
    $this->travel(30)->days();

    expect(fn () => $this->service->attempt('0123456789', 'secret123'))
        ->toThrow(AccountLockedException::class);
});

/** §8 test 3, second half — the counter resets on success. */
it('resets the counter on a successful login', function () {
    $user = accountWithPhone('0123456789', $this->aim);

    foreach (range(1, 2) as $ignored) {
        try {
            $this->service->attempt('0123456789', 'wrong');
        } catch (InvalidCredentialsException) {
        }
    }

    expect($user->fresh()->failed_login_attempts)->toBe(2);

    $this->service->attempt('0123456789', 'secret123');

    // Without this, three typos spread over months would eventually lock someone out.
    expect($user->fresh()->failed_login_attempts)->toBe(0)
        ->and($user->fresh()->locked_until)->toBeNull();
});

/**
 * §8 test 4 — throttling is keyed on the ACCOUNT, not the IP.
 *
 * ⚠ An attacker changing IP must not get a fresh allowance. This is the reason the state
 * lives on the users row rather than in a request-keyed rate limiter.
 */
it('does not reset the counter when the request IP changes', function () {
    $user = accountWithPhone('0123456789', $this->aim);

    foreach (['203.0.113.7', '198.51.100.4', '192.0.2.9'] as $ip) {
        $this->serverVariables = ['REMOTE_ADDR' => $ip];

        try {
            $this->service->attempt('0123456789', 'wrong');
        } catch (InvalidCredentialsException) {
        }
    }

    expect($user->fresh()->failed_login_attempts)->toBe(3);
});

it('writes both failures and successes to security_events', function () {
    $user = accountWithPhone('0123456789', $this->aim);

    try {
        $this->service->attempt('0123456789', 'wrong');
    } catch (InvalidCredentialsException) {
    }

    $this->service->attempt('0123456789', 'secret123');

    // Ordered by id: created_at has second precision, so both rows can share a timestamp.
    expect(SecurityEvent::query()->orderBy('id')->pluck('event_type')->all())
        ->toBe(['LOGIN_FAILED', 'LOGIN_SUCCESS']);
});

/**
 * ⚠ The row for an unknown number carries a NULL user_id, which is BR-AT11's retention
 * discriminator: attempts against a number in no account expire at 90 days, attempts
 * against a real account are kept forever.
 */
it('records an attempt against an unknown number with no user_id', function () {
    try {
        $this->service->attempt('0119999999', 'whatever');
    } catch (InvalidCredentialsException) {
    }

    $event = SecurityEvent::query()->sole();

    expect($event->user_id)->toBeNull()
        ->and($event->identifier)->toBe('0119999999')
        ->and($event->isUnattributed())->toBeTrue();
});

/**
 * ⚠ The counter must advance even when the security_events write fails. That write is
 * deliberately non-blocking (audit-trail.spec.md BR-AT8), and a throttle that depended on it
 * would be disabled for the whole group by one broken table — failing OPEN at exactly the
 * moment the evidence stopped being recorded.
 */
it('still throttles when security_events cannot be written', function () {
    $user = accountWithPhone('0123456789', $this->aim);

    Illuminate\Support\Facades\Schema::rename('security_events', 'security_events_missing');

    try {
        foreach (range(1, 3) as $ignored) {
            try {
                $this->service->attempt('0123456789', 'wrong');
            } catch (InvalidCredentialsException) {
            }
        }
    } finally {
        Illuminate\Support\Facades\Schema::rename('security_events_missing', 'security_events');
    }

    expect($user->fresh()->failed_login_attempts)->toBe(3)
        ->and($user->fresh()->locked_until)->not->toBeNull();
});

/**
 * §8 test 8 — THE MOST IMPORTANT ONE. Removing the checkbox is not the same as disabling
 * the feature, because the field can be posted directly.
 *
 * ⚠ The capability is absent from the signature rather than defended against at the edge:
 * attempt() takes no $remember parameter, so there is no argument a request could supply.
 * Asserted three ways — no recaller cookie, no such parameter, no remember_token column for
 * one to be minted against.
 */
it('creates no persistent login, whatever a request posts', function () {
    accountWithPhone('0123456789', $this->aim);

    $this->service->attempt('0123456789', 'secret123');

    $recaller = collect(app('cookie')->getQueuedCookies())
        ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_'));

    expect($recaller)->toBeNull()
        ->and(Auth::viaRemember())->toBeFalse();
});

it('has no remember parameter to post into', function () {
    $parameters = (new ReflectionMethod(AuthenticationService::class, 'attempt'))->getParameters();

    expect(collect($parameters)->pluck('name')->all())->toBe(['identifier', 'password']);
});

it('has no remember_token column and declares remember-me disabled', function () {
    // Laravel's default users migration creates rememberToken(); this one must not, and it
    // may not be added later. An unused column reads as "the feature exists, it just isn't
    // wired up" — which is how it gets wired up (BR-A4).
    expect(Illuminate\Support\Facades\Schema::hasColumn('users', 'remember_token'))->toBeFalse()
        ->and(config('auth.remember_me.enabled'))->toBeFalse();
});

it('regenerates the session id on success', function () {
    accountWithPhone('0123456789', $this->aim);

    session()->put('_token', 'before');
    $before = session()->getId();

    $this->service->attempt('0123456789', 'secret123');

    // Session fixation: the id the visitor arrived with must not survive authentication.
    expect(session()->getId())->not->toBe($before);
});

it('reads every number from policy_configurations and refuses to guess', function () {
    $user = accountWithPhone('0123456789', $this->aim);

    PolicyConfiguration::query()
        ->where('company_id', $this->aim->id)
        ->where('key', 'auth.throttle.tier_4.attempts')
        ->forceDelete();

    // ⚠ Throws rather than falling back. A default compiled into the code would be a second
    // source for the number, and for the throttle tiers a wrong value is a security failure
    // that looks like a working login screen (conventions.md §5).
    expect(fn () => app(LoginThrottle::class)->isPermanentlyLocked($user->fresh()))
        ->toThrow(RuntimeException::class);
});
