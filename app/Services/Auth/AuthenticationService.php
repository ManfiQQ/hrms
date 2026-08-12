<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\AccountLockedException;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use App\Services\Audit\SecurityEventLogger;
use App\Support\Auth\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Login — auth-rbac.spec.md §5.1, BR-A1 to BR-A5.
 *
 * LoginController is thin; every decision below lives here. The order of the steps is the
 * specification and is not an implementation detail:
 *
 *   1. Normalise the submitted phone number (BR-A1).
 *   2. Check the throttle BEFORE verifying the password (BR-A3).
 *   3. On failure: increment the counter, write security_events, return a generic message.
 *   4. On success: reset the counter, regenerate the session id.
 *
 * ⚠ THERE IS NO $remember PARAMETER, AND NONE MAY BE ADDED (BR-A4). Removing the checkbox
 * from the form is not the same as disabling the feature, because the field can be posted
 * directly — so the capability is absent from the signature rather than defended against at
 * the edge. A persistent cookie would re-authenticate someone past BR-A6's two-hour window,
 * and much of this workforce logs in from shared terminals at the factory, studio and
 * galleria, where a remembered login means the account is never really logged out. It is
 * also a second credential that would have to be invalidated on password change and on
 * freeze; not having it removes a thing that can be forgotten.
 */
class AuthenticationService
{
    public function __construct(
        private readonly LoginThrottle $throttle,
        private readonly SecurityEventLogger $securityEvents,
    ) {}

    /**
     * Attempt a login. Returns the authenticated user, or throws.
     *
     * ⚠ Every failure path throws the SAME message (InvalidCredentialsException::MESSAGE).
     * An unknown number, a wrong password and a locked account are indistinguishable to the
     * caller, because the username is a phone number and an existence oracle here is worth
     * more to an attacker than it looks.
     *
     * @throws InvalidCredentialsException|AccountLockedException
     */
    public function attempt(string $identifier, string $password): User
    {
        // 1 — BR-A1. Called through the single normaliser the employee form also uses; two
        // implementations would diverge, and the divergence is silent.
        $normalised = PhoneNumber::normalise($identifier);

        if (! PhoneNumber::isValid($normalised)) {
            // Not "invalid format" — the same generic failure. Telling an attacker which of
            // their inputs was well-formed is a free filter over the number space.
            $this->securityEvents->record('LOGIN_FAILED', $normalised);

            throw InvalidCredentialsException::make();
        }

        $user = $this->findByPhone($normalised);

        if ($user === null) {
            // ⚠ No throttle state to advance: there is no account to lock, and nothing to
            // brute-force behind a number that belongs to nobody. The event is still
            // recorded — a run of attempts against numbers in no account is exactly the
            // pattern BR-AT11 keeps for 90 days.
            $this->securityEvents->record('LOGIN_FAILED', $normalised);

            throw InvalidCredentialsException::make();
        }

        // 2 — BEFORE the password check. A locked account must fail without one, or the lock
        // is a delay rather than a lock and each guess still returns information.
        $this->assertNotLocked($user);

        // 3 — verify.
        if (! Hash::check($password, $user->password)) {
            // ⚠ The counter advances FIRST and independently of the log write. The
            // security_events write is deliberately non-blocking (audit-trail.spec.md
            // BR-AT8), so ordering it after the counter is what stops one broken table from
            // disabling throttling for the whole group.
            $this->throttle->recordFailure($user);
            $this->securityEvents->record('LOGIN_FAILED', $normalised, $user);

            throw InvalidCredentialsException::make();
        }

        // 4 — BR-A3: the counter resets on success, so three typos spread over months never
        // accumulate into a lockout.
        $this->throttle->reset($user);

        // ⚠ No second argument. Auth::login($user, true) is what mints a recaller cookie,
        // and this call site is the only one in the system.
        Auth::login($user);

        // Session fixation: the id the visitor arrived with must not survive authentication.
        session()->regenerate();

        $this->securityEvents->record('LOGIN_SUCCESS', $normalised, $user);

        return $user;
    }

    /**
     * @throws AccountLockedException
     */
    private function assertNotLocked(User $user): void
    {
        if ($this->throttle->isPermanentlyLocked($user)) {
            $this->securityEvents->record('LOGIN_FAILED', $user->phone_no, $user);

            throw AccountLockedException::permanent();
        }

        $until = $this->throttle->lockedUntil($user);

        if ($until !== null) {
            $this->securityEvents->record('LOGIN_FAILED', $user->phone_no, $user);

            throw AccountLockedException::timed($until);
        }
    }

    /**
     * The account behind a normalised phone number.
     *
     * ⚠ One query against `users`, and no join through `employees` (adr/0006). The username
     * belongs to the account, which is what lets an account with no employee record — every
     * Master Admin, and the Director — have one at all. Until 2026-08-12 this resolved
     * through the employee record, and the installer's own account was therefore unreachable
     * by any input.
     *
     * It also removes a `withoutGlobalScope(TenantScope::class)` release that the join
     * needed: `users` carries no tenant scope, so the pre-authentication path — the one with
     * no user to scope against — now has nothing to release.
     */
    private function findByPhone(string $normalised): ?User
    {
        return User::query()->where('phone_no', $normalised)->first();
    }
}
