<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * BR-A3's fourth tier is lifted by hand — `HR` or Master Admin only, auth-rbac.spec.md §7.
 *
 * ⚠ THERE IS NO AUTOMATIC EXPIRY ON THE PERMANENT LOCK, and that is the decision. At twelve
 * cumulative failures the likeliest explanation is no longer a typo, so somebody has to look
 * at it — and that person is HR, not a timer.
 *
 * ⚠ The counter and the timed lock are cleared TOGETHER. A permanent lock is
 * `failed_login_attempts` having reached the fourth tier, and `locked_until` is the separate
 * timed lock — clearing only one leaves an account that still cannot log in for a reason the
 * screen no longer shows.
 */
class UnlockAccount
{
    /**
     * ⚠ `locked_until` is the audited field, and it is deliberately not a credential. The
     * accountable fact is that somebody lifted a lock, not what the counter read.
     */
    public const AUDITS = [
        User::class => ['locked_until'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $user, ?string $reason = null): void
    {
        DB::transaction(function () use ($user, $reason) {
            $wasLockedUntil = $user->locked_until;
            $attempts = $user->failed_login_attempts;

            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $this->audit->record(
                action: 'account.unlocked',
                subject: $user,
                field: 'locked_until',
                old: $wasLockedUntil?->toDateTimeString() ?? "locked after {$attempts} failed attempts",
                new: null,
                reason: $reason ?? "Account unlocked after {$attempts} failed login attempts.",
            );
        });
    }
}
