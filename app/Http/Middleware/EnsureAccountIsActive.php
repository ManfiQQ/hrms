<?php

namespace App\Http\Middleware;

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

    public function __construct(private readonly LoginThrottle $throttle) {}

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

        // ⚠ EXPIRED IS NOT IMPLEMENTED, DELIBERATELY, AND IS NOT APPROXIMATED.
        //
        // BR-A17 counts ten days from the effective_date — the LAST WORKING DAY, not the day
        // HR typed the change. That date lives on employee_status_history, which has no
        // migration; employees carries staff_status and no date for when it took effect, so
        // there is nothing to count from.
        //
        // The column is not this module's to add: employee_status_history belongs to
        // Employee Master, and designing its shape from an Auth branch is the
        // code-before-spec pattern Principle #1 exists to prevent — the same reason
        // audit_logs was not created by the tenant-scope PR (adr/0005 decision 5).
        //
        // The failure direction is safe: the freeze below never lifts, so a terminated
        // account stays read-only forever instead of becoming inaccessible on day ten.
        // Access is NARROWER than the rule requires, not wider. What is missing is the
        // account ever going fully dark, and BR-A19's countdown, which needs the same date.
        // Both land with Employee Master; §8 test 24 cannot pass until then.

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
