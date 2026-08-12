<?php

namespace App\Services\Audit;

use App\Models\Employee;
use App\Models\Scopes\TenantScope;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only permitted writer of security_events (audit-trail.spec.md §5.2).
 *
 * ⚠ BR-AT8 — THIS WRITE NEVER BLOCKS. Every failure is caught, written to the application
 * file log at error level, and swallowed as far as the request is concerned.
 *
 * Authentication must not depend on a table write. If it did, one database problem — a full
 * disk, a locked table, a failed migration, a connection limit — would make the system
 * impossible to log into, INCLUDING FOR THE MASTER ADMIN who has to log in to repair it.
 * That is not a degraded system; it is a locked room with the key inside.
 *
 * Two consequences that must keep holding:
 *
 *   1. The BR-A3 throttle counter NEVER reads this table. A counter derived from these rows
 *      would fail OPEN on exactly the fault that suppresses the log.
 *   2. The failure is LOUD in the file log. Silent loss of the security record is the
 *      failure mode this rule trades for; it is acceptable only because it is visible
 *      somewhere.
 *
 * ⚠ The caller must not wrap this in a transaction, and this class cannot enforce it. It
 * writes on the ordinary connection, so a write inside a caller's transaction would roll
 * back with it. Nothing in the authentication path opens one today; a second connection was
 * not worth its cost to make the rule structural. Stated, not guaranteed (BR-AT8).
 */
class SecurityEventLogger
{
    /**
     * Record an authentication event.
     *
     * @param  string  $eventType   one of SecurityEvent::EVENT_TYPES
     * @param  string  $identifier  the login identifier, ALREADY NORMALISED — see below
     * @param  User|null  $user     the account, when the identifier resolved to one
     *
     * ⚠ This service does not normalise. BR-A1 requires ONE normaliser, called by both the
     * login attempt and the employee form; the Auth module owns it and it does not exist
     * yet. A second implementation here would be the divergence that rule exists to
     * prevent. Until AuthenticationService lands this is a contract with nothing enforcing
     * it, and an unnormalised value splits one number into several identifiers.
     */
    public function record(string $eventType, string $identifier, ?User $user = null): void
    {
        try {
            SecurityEvent::create([
                // ⚠ THE RETENTION DISCRIMINATOR (BR-AT11): a row with a user_id is kept
                // forever, a row without one for 90 days. Never populate it defensively —
                // a placeholder silently converts a 90-day row into a permanent one.
                'user_id' => $user?->getKey(),

                'event_type' => $eventType,
                'identifier' => $identifier,

                // Recorded, and neither is evidence (§11). Stored verbatim and unparsed;
                // null outside an HTTP context. NEITHER is ever read back into an
                // authorization, throttling, or lockout decision.
                'ip_address' => $this->request()?->ip(),
                'user_agent' => $this->request()?->userAgent(),

                // Reporting convenience only, never an access control (BR-AT9). Null where
                // it cannot be resolved, which includes every attempt against a number that
                // matches no account.
                'company_id' => $this->companyIdFor($user),
            ]);
        } catch (Throwable $e) {
            // ⚠ Swallowed on purpose — see the class docblock. Loud in the file log so the
            // loss is visible somewhere, and never rethrown.
            Log::error('Failed to write a security event; authentication continues.', [
                'event_type' => $eventType,
                'identifier' => $identifier,
                'user_id' => $user?->getKey(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * The account's employer, where there is one to resolve.
     *
     * ⚠ Loaded WITHOUT TenantScope, the same carve-out ReadScopeResolver takes and for the
     * same reason: the scope would ask who is reading, and at the moment a security event is
     * written there may be nobody authenticated at all. It is safe because it is keyed on
     * the account's own employee_id and can return that employee and nothing else.
     */
    private function companyIdFor(?User $user): ?int
    {
        if ($user?->employee_id === null) {
            return null;
        }

        return Employee::withoutGlobalScope(TenantScope::class)
            ->whereKey($user->employee_id)
            ->value('company_id');
    }

    /** Null in console, queue and test contexts that have no request bound. */
    private function request(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }
}
