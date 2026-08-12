<?php

use App\Actions\Auth\RedeemActivationToken;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PolicyConfiguration;
use App\Models\User;
use App\Services\Auth\AuthenticationService;
use Illuminate\Support\Facades\Schema;

/**
 * BR-AT8 — the `security_events` write is non-blocking, proved with the table actually gone.
 *
 * ⚠ MOVED HERE FROM tests/Feature ON 2026-08-12, AND THE MOVE IS A BUG FIX.
 *
 * Both of these tests rename a table, which is DDL — and **MySQL commits implicitly on DDL**.
 * Inside `RefreshDatabase` that commits the wrapping transaction, so every row the test
 * created was written permanently and survived teardown. The leak was invisible until the
 * suite happened to grow: a later test then failed on `Duplicate entry 'AIM' for key
 * companies_code_unique`, pointing at a file that had nothing to do with the cause.
 *
 * The truncation-based suite has no wrapping transaction to lose, which is what this suite
 * exists for — the same reason the sequence and rollback tests live here.
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
});

/**
 * ⚠ The counter must advance even when the log cannot be written. That write is deliberately
 * non-blocking, so a throttle depending on it would fail OPEN at exactly the moment the
 * evidence stopped being recorded.
 */
it('still throttles when security_events cannot be written', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create();
    $user = User::factory()->forEmployee($employee)->create([
        'phone_no' => '0123456789',
        'password' => 'secret123',
    ]);

    Schema::rename('security_events', 'security_events_missing');

    try {
        foreach (range(1, 3) as $ignored) {
            try {
                app(AuthenticationService::class)->attempt('0123456789', 'wrong');
            } catch (InvalidCredentialsException) {
            }
        }
    } finally {
        Schema::rename('security_events_missing', 'security_events');
    }

    expect($user->fresh()->failed_login_attempts)->toBe(3)
        ->and($user->fresh()->locked_until)->not->toBeNull();
});

/**
 * ⚠ A broken log must not stop an employee getting into the system on their first day.
 */
it('still activates an account when security_events cannot be written', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create();
    $user = User::factory()->forEmployee($employee)->create([
        'phone_no' => '0119999999',
        'activation_token' => 'a-live-token',
        'activation_expires_at' => now()->addHours(48),
        'activation_used_at' => null,
    ]);

    Schema::rename('security_events', 'security_events_missing');

    try {
        $redeemed = app(RedeemActivationToken::class)->execute('a-live-token');

        expect($redeemed->id)->toBe($user->id)
            ->and($user->fresh()->activation_used_at)->not->toBeNull();
    } finally {
        Schema::rename('security_events_missing', 'security_events');
    }
});
