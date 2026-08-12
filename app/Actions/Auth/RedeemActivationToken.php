<?php

namespace App\Actions\Auth;

use App\Events\Auth\AccountActivated;
use App\Exceptions\Auth\InvalidActivationTokenException;
use App\Models\User;
use App\Services\Audit\SecurityEventLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * BR-A21 — redeem a single-use activation token, auth-rbac.spec.md §5.6.
 *
 * ⚠ THIS IS THE HIGHEST-VALUE UNAUTHENTICATED ENDPOINT IN THE SYSTEM. Redemption
 * authenticates the holder outright and lets them set the account's first password — the
 * account was created with no usable one (adr/0004 decision 7). Whoever holds a live token
 * becomes that employee, and everything they then do is attributed to them in the audit log.
 *
 * Three consequences, none of them optional:
 *
 *   - REJECTION IS GENERIC. Used, expired and unknown are indistinguishable to the caller,
 *     or the URL becomes an oracle for which tokens ever existed.
 *   - REDEMPTION IS ATOMIC. The row is taken with lockForUpdate, so two simultaneous scans
 *     cannot both succeed.
 *   - THE TOKEN DIES ON REDEMPTION. `activation_used_at` is stamped inside the same
 *     transaction that reads it.
 */
class RedeemActivationToken
{
    public function __construct(private readonly SecurityEventLogger $securityEvents) {}

    /**
     * @throws InvalidActivationTokenException
     */
    public function execute(string $token): User
    {
        // ⚠ The whole check-and-stamp runs inside one transaction with the row locked. A
        // read outside a transaction would be a check-then-act race: two scans of one QR —
        // an employee tapping twice on a slow connection, or two people sent the same image
        // — would both read `activation_used_at` as null and both proceed.
        $user = DB::transaction(function () use ($token) {
            $user = User::query()
                ->where('activation_token', $token)
                ->lockForUpdate()
                ->first();

            // ⚠ All three rejections throw the SAME exception, deliberately. Distinguishing
            // them would confirm whether a token ever existed, and the reward for finding a
            // live one is an account rather than a hint.
            if ($user === null) {
                throw InvalidActivationTokenException::make();
            }

            if ($user->activation_used_at !== null) {
                throw InvalidActivationTokenException::make();
            }

            if ($user->activation_expires_at === null || $user->activation_expires_at->isPast()) {
                throw InvalidActivationTokenException::make();
            }

            // ⚠ Stamped under the lock. The second scan will read this value and be refused.
            //
            // ⚠ activation_downloaded_at is NOT touched here. It records that HR fetched the
            // QR image, and serving that image is what sets it (BR-A22) — the system records
            // what it can observe, and this Action observes a redemption, not a download.
            $user->forceFill(['activation_used_at' => now()])->save();

            return $user;
        });

        // ⚠ Authenticated only after the transaction commits. Logging somebody in inside a
        // transaction that might roll back would leave a session for a redemption that never
        // happened.
        //
        // The previous occupant of this browser is replaced deliberately: much of this
        // workforce activates on a SHARED TERMINAL, and a scan must not inherit whoever was
        // signed in before.
        Auth::login($user);
        session()->regenerate();

        // ⚠ Outside the transaction and non-blocking (audit-trail.spec.md BR-AT8). A broken
        // security_events table must not stop an employee activating their account — the
        // failure goes to the file log and the request continues.
        //
        // Recorded even though this is a SUCCESS: it is the moment an account changed hands,
        // which is a security event whether or not anything went wrong.
        $this->securityEvents->record('ACTIVATION_REDEEMED', $user->phone_no, $user);

        // §5.6 requires HR to be notified. Nothing listens yet — the Notification Engine has
        // no spec, and inventing its delivery rules here is what Principle #1 prevents.
        Event::dispatch(new AccountActivated($user));

        // must_change_password is left TRUE. BR-A23's gate is what forces the employee to set
        // their own password before reaching anything else — this Action does not need to
        // redirect, and duplicating the rule here would be a second place for it to be wrong.
        return $user;
    }
}
