<?php

namespace App\Http\Middleware;

use App\Services\Auth\AccountExpiry;
use App\Services\Auth\LoginThrottle;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The account state gate — auth-rbac.spec.md §5.2.
 *
 * ⚠ FREEZE IS ENFORCED HERE, NOT IN EACH POLICY. A policy-by-policy freeze check is the one
 * that gets forgotten in the twentieth policy, and the omission returns a successful write
 * rather than an error.
 *
 * This still matters for TERMINATED even though BR-A15 kills that user's sessions: the
 * person may log in again during the ten-day window, and this gate is what makes that
 * session read-only.
 */
class EnsureAccountIsActive
{
    /** Terminal statuses freeze the account immediately (BR-A2, BR-A15). */
    private const TERMINAL_STATUSES = ['RESIGNED', 'TERMINATED'];

    /** Methods that only read. Everything else is a write and is refused to a frozen account. */
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly LoginThrottle $throttle,
        private readonly AccountExpiry $expiry,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // ⚠ Permanently locked → logged out. An account can be locked while its session is
        // open (HR locks it, or the twelfth failure lands from another device), and the
        // session must not outlive the lock.
        if ($this->throttle->isPermanentlyLocked($user)) {
            return $this->logout($request, 'This account is locked. HR or a Master Admin must unlock it.');
        }

        // ⚠ EXPIRED IS CHECKED BEFORE FROZEN, AND THE ORDER IS THE RULE.
        //
        // An expired account is also frozen — it holds a terminal status either way — so a
        // freeze check reached first would grant it read access forever and the countdown
        // would never end anything. Expiry is the stricter of the two states and has to be
        // asked about first.
        //
        // BR-A17 counts ten days from `effective_date`, the LAST WORKING DAY, never from the
        // day HR typed the change. AccountExpiry owns that arithmetic; this gate owns only
        // what happens when it comes back true.
        if ($this->expiry->hasExpired($user)) {
            // Session invalidated and logged out (§5.2). All data remains in the system —
            // expiry closes the account, not the record.
            return $this->logout($request, 'This account has expired: its post-employment access window has ended.');
        }

        if ($this->isFrozen($user) && ! $this->isReadRequest($request)) {
            // Frozen: reads of own data permitted, all writes rejected. Not logged out —
            // BR-A15 keeps the account readable so the person can still see their own
            // record during the window.
            abort(403, 'This account is frozen: its employment has ended. Read access only (BR-A15).');
        }

        return $next($request);
    }

    private function isFrozen($user): bool
    {
        $status = $user->employee?->staff_status;

        return $status !== null && in_array($status, self::TERMINAL_STATUSES, true);
    }

    private function isReadRequest(Request $request): bool
    {
        return in_array($request->method(), self::READ_METHODS, true);
    }

    private function logout(Request $request, string $message): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort(403, $message);
    }
}
