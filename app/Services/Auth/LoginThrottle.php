<?php

namespace App\Services\Auth;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * BR-A3's four-tier failed-login throttle.
 *
 * | Cumulative failures | Result |
 * |---------------------|-----------------------------------------|
 * | 3                   | Locked 5 minutes                        |
 * | 6                   | Locked 10 minutes                       |
 * | 9                   | Locked 15 minutes                       |
 * | 12                  | Locked PERMANENTLY — HR or Master Admin |
 *
 * ⚠ THE TIERS ARE LOAD-BEARING, NOT DEFENCE IN DEPTH. The username is not secret — it is the
 * employee's phone number, known to colleagues — and the password minimum is six characters,
 * chosen by the client over the recommended eight. Password strength is therefore not
 * carrying the security here; this class is. Relax the tiers, or enforce them anywhere other
 * than server-side, and brute force becomes practical against a system holding salary and
 * identity documents.
 *
 * ⚠ KEYED ON THE ACCOUNT, NEVER THE IP. An attacker changing IP must not get a fresh
 * allowance, which is the whole reason the state lives on the users row rather than in a
 * rate limiter keyed by request.
 *
 * ⚠ IT NEVER READS security_events. That table's write is deliberately non-blocking
 * (audit-trail.spec.md BR-AT8), so a counter derived from it would fail OPEN on exactly the
 * fault that suppresses the log — the counter would read zero and every account would be
 * unthrottled at the moment the evidence stopped being recorded.
 */
class LoginThrottle
{
    /** Tier thresholds and lock durations, all read from policy_configurations. */
    private const TIERS = [
        ['attempts' => 'auth.throttle.tier_1.attempts', 'minutes' => 'auth.throttle.tier_1.lock_minutes'],
        ['attempts' => 'auth.throttle.tier_2.attempts', 'minutes' => 'auth.throttle.tier_2.lock_minutes'],
        ['attempts' => 'auth.throttle.tier_3.attempts', 'minutes' => 'auth.throttle.tier_3.lock_minutes'],
    ];

    private const PERMANENT_TIER = 'auth.throttle.tier_4.attempts';

    public function __construct(private readonly AuthPolicy $policy) {}

    /**
     * Is this account locked right now?
     *
     * ⚠ Checked BEFORE the password is verified (§5.1 step 2). A locked account fails
     * without a password check — otherwise the lock is a delay, not a lock, and an attacker
     * still learns whether each guess was right.
     */
    public function isLocked(User $user): bool
    {
        return $this->isPermanentlyLocked($user) || $this->lockedUntil($user) !== null;
    }

    /**
     * ⚠ Read FIRST, and derived from the counter rather than a flag.
     *
     * A locked_permanently boolean would be a second way to say "locked", and the unlock
     * path that cleared only one of the two would be a silent hole (adr/0003 decision 1's
     * reasoning). One fact, read one way.
     */
    public function isPermanentlyLocked(User $user): bool
    {
        return $user->failed_login_attempts >= $this->policy->int(self::PERMANENT_TIER, $user);
    }

    /** The timed lock still in force, or null. Null does NOT mean "not locked" — see isLocked(). */
    public function lockedUntil(User $user): ?CarbonInterface
    {
        $until = $user->locked_until;

        return $until !== null && $until->isFuture() ? $until : null;
    }

    /**
     * Record a failed attempt and apply whichever tier it reaches.
     *
     * ⚠ This must succeed independently of the security_events write (§5.1 step 3): the
     * counter has to advance even when the log cannot be written, or one broken table
     * disables throttling for the whole group.
     */
    public function recordFailure(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;
        $user->failed_login_attempts = $attempts;

        if ($attempts >= $this->policy->int(self::PERMANENT_TIER, $user)) {
            // Permanent. locked_until is left alone: the permanent check does not read it,
            // and writing a far-future date would be a second representation of one state.
            $user->save();

            return;
        }

        foreach (array_reverse(self::TIERS) as $tier) {
            if ($attempts >= $this->policy->int($tier['attempts'], $user)) {
                $user->locked_until = now()->addMinutes($this->policy->int($tier['minutes'], $user));
                break;
            }
        }

        $user->save();
    }

    /**
     * BR-A3: the counter resets on successful login.
     *
     * Without this, three typos spread over months would eventually lock someone out — the
     * counter would only ever climb.
     */
    public function reset(User $user): void
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }
}
