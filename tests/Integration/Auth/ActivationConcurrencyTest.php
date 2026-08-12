<?php

use App\Actions\Auth\RedeemActivationToken;
use App\Exceptions\Auth\InvalidActivationTokenException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PolicyConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * §5.6 — "Two simultaneous scans of one token must not both succeed."
 *
 * ⚠ IT IS NOT A THEORETICAL RACE. One QR image reaches the employee over WhatsApp, where it
 * can be forwarded, opened on two devices, or tapped twice on a slow connection. Both
 * requests then read `activation_used_at` as null and both proceed — and redemption
 * AUTHENTICATES, so the loser of that race is not shown an error, they are signed in as
 * somebody else's account.
 *
 * ⚠ IN tests/Integration BECAUSE IT NEEDS TWO REAL CONNECTIONS AND NO WRAPPING TRANSACTION.
 * Under RefreshDatabase both sessions sit inside one test-owned transaction that is never
 * committed, so the lock can never be observed doing anything — the test would pass with or
 * without it, which is exactly the empty guard conventions.md §9 warns about.
 */
beforeEach(function () {
    config(['database.connections.second' => config('database.connections.mysql')]);
    DB::purge('second');

    $ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($ahs)->create(['code' => 'AIM']);

    foreach ([$ahs, $this->aim] as $company) {
        foreach (['auth.throttle.tier_4.attempts' => '12', 'auth.activation.validity_hours' => '48'] as $key => $value) {
            PolicyConfiguration::create([
                'company_id' => $company->id,
                'key' => $key,
                'value' => $value,
                'effective_from' => now()->toDateString(),
            ]);
        }
    }

    $employee = Employee::factory()->forCompany($this->aim)->create();

    $this->account = User::factory()->forEmployee($employee)->create([
        'phone_no' => '0123456789',
        'activation_token' => 'one-token-two-scans',
        'activation_expires_at' => now()->addHours(48),
        'activation_used_at' => null,
    ]);
});

afterEach(function () {
    DB::purge('second');
});

/**
 * ⚠ THE ASSERTION THAT DISTINGUISHES A REAL LOCK FROM NO LOCK — and it asserts the OUTCOME,
 * not whether anyone waited.
 *
 * The lesson from PR #27, applied first time here: "the second session blocked" is true
 * whether or not `lockForUpdate()` is present, because the UPDATE contends either way. What
 * differs is what the second session READS.
 *
 * Under REPEATABLE READ a plain SELECT returns the snapshot taken when the transaction
 * began. So a second scan whose transaction opened before the first committed sees
 * `activation_used_at` as still null — and redeems a token that is already spent. A locking
 * read always sees the latest committed row, and refuses.
 */
it('refuses a second scan whose transaction opened before the first committed', function () {
    // Scan two opens first: its snapshot predates scan one's write.
    DB::connection('second')->beginTransaction();
    DB::connection('second')->table('users')->count();   // materialise the snapshot

    // Scan one redeems and commits.
    $first = app(RedeemActivationToken::class)->execute('one-token-two-scans');

    // Scan two now tries, on its own connection.
    config(['database.default' => 'second']);

    $secondSucceeded = false;

    try {
        app(RedeemActivationToken::class)->execute('one-token-two-scans');
        $secondSucceeded = true;
    } catch (InvalidActivationTokenException) {
        // expected — the token is spent
    } finally {
        DB::connection('second')->rollBack();
        config(['database.default' => 'mysql']);
    }

    expect($first->id)->toBe($this->account->id)
        ->and($secondSucceeded)->toBeFalse(
            'Two scans of one QR both redeemed it. Redemption AUTHENTICATES, so the second '.
            'holder was signed in as this employee (§5.6).'
        );

    // Exactly one redemption landed.
    expect($this->account->fresh()->activation_used_at)->not->toBeNull();
});

it('stamps the token exactly once, however many times it is scanned', function () {
    $first = app(RedeemActivationToken::class)->execute('one-token-two-scans');
    $usedAt = $this->account->fresh()->activation_used_at;

    // A second and third attempt must change nothing — not the timestamp, not the account.
    foreach (range(1, 2) as $ignored) {
        expect(fn () => app(RedeemActivationToken::class)->execute('one-token-two-scans'))
            ->toThrow(InvalidActivationTokenException::class);
    }

    expect($this->account->fresh()->activation_used_at->eq($usedAt))->toBeTrue()
        ->and($first->id)->toBe($this->account->id);
});
