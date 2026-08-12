<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * BR-A7 — `HR` and Master Admin reset another account's password, auth-rbac.spec.md §7.
 *
 * ⚠ NOT self-service by email. Most of this workforce has no email address to send a link
 * to, which is why `password_reset_tokens` was never created (`schema.md` § Status). And not
 * `ACCOUNT`, who reads everything and administers nothing.
 *
 * ⚠ THE NEW PASSWORD IS TEMPORARY BY CONSTRUCTION. `must_change_password` is set, so BR-A23's
 * gate stops the employee everywhere except the password screen until they choose their own.
 * HR knows this credential — that is unavoidable when HR is the reset path — and the gate is
 * what bounds how long it matters.
 *
 * ⚠ EVERY SESSION IS TERMINATED. A password reset that left existing sessions alive would
 * leave whoever prompted the reset still signed in, which is the case where a reset is most
 * likely to have been requested for a reason.
 */
class ResetAccountPassword
{
    /**
     * ⚠ `password_changed_at`, NOT `password`. The audit trail must never carry a credential:
     * `audit_logs` is readable by `HR` and `ASSISTANT_DIRECTOR` within their read scope
     * (BR-AT9), and a hash sitting in it is a hash offline-crackable by anyone who can read
     * the screen. The timestamp records that the reset happened, which is the accountable
     * fact; the value is not.
     */
    public const AUDITS = [
        User::class => ['password_changed_at'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $user, string $newPassword, ?string $reason = null): void
    {
        DB::transaction(function () use ($user, $newPassword, $reason) {
            $previouslyChangedAt = $user->password_changed_at;

            $user->forceFill([
                'password' => $newPassword,          // hashed by the model cast
                'password_changed_at' => now(),

                // BR-A23 — the employee must replace it before reaching anything else.
                'must_change_password' => true,
            ])->save();

            // Sessions die with the credential they were established under.
            $terminated = DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();

            $this->audit->record(
                action: 'account.password_reset',
                subject: $user,
                field: 'password_changed_at',
                old: $previouslyChangedAt?->toDateTimeString(),
                new: now()->toDateTimeString(),
                reason: $reason ?? "Password reset by an administrator; {$terminated} session(s) ended.",
            );
        });
    }
}
