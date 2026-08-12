<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Who tried to get in — login success and failure, lockout, password change by the account
 * holder, activation redemption (audit-trail.spec.md BR-AT1).
 *
 * What the SUBJECT did or attempted. An action performed ON an account by someone else — HR
 * password reset, unlock, a system_access change, the TERMINATED session kill — is a data
 * change and belongs in AuditLog. BR-A15's session-kill write must sit inside the freeze
 * transaction and roll back with it, which AuditLog gives (BR-AT7) and this table explicitly
 * does not (BR-AT8).
 *
 * ⚠ The write to this table is non-blocking and lives OUTSIDE any transaction. A failure is
 * logged to file and the request continues: authentication must not depend on a table write,
 * or one database problem makes the system impossible to log into — including for the Master
 * Admin who has to log in to repair it. It follows that the BR-A3 throttle counter NEVER
 * reads these rows; a counter derived from them would fail OPEN on exactly the fault that
 * suppresses the log.
 */
class SecurityEvent extends Model
{
    use HasFactory;

    /**
     * Carries NO tenant scope at all — not TenantScope, not SharedTenantScope, and not the
     * SystemTenantScope AuditLog uses.
     *
     * A security event happens BEFORE AUTHENTICATION. There is no authenticated user from
     * whom to resolve a read scope, and in the failed-attempt case there may be no account at
     * all: an attempt against a phone number that has never existed here has no subject, so
     * no employer, so no company. SystemTenantScope does not fit either, because it reads the
     * account's system_access and there may be no account to read it from.
     *
     * `company_id` is filled where knowable and left null where it is not. It is a REPORTING
     * CONVENIENCE, NEVER AN ACCESS CONTROL. Access control is the read-time permission check
     * in BR-AT9: Master Admin sees everything, HR and ASSISTANT_DIRECTOR see within their
     * read scope, and a null-user_id row — belonging to no company — is Master Admin only.
     *
     * This constant is what the architecture guard test reads. The exemption must be
     * DECLARED, never expressed by silence, so that "deliberately unscoped" and "someone
     * forgot" stay distinguishable (adr/0005 decision 6, conventions.md §2 fourth carve-out).
     */
    public const TENANT_SCOPE_EXEMPT = 'events are written before authentication, so there may be no account from which to resolve a scope (audit-trail.spec.md §3)';

    /** Read off auth-rbac.spec.md. A new value is a change to what Auth does. */
    public const EVENT_TYPES = [
        'LOGIN_SUCCESS',
        'LOGIN_FAILED',
        'ACCOUNT_LOCKED',
        'PASSWORD_CHANGED',
        'ACTIVATION_REDEEMED',
    ];

    /** Append-only: created_at alone, managed by Eloquent; updated_at does not exist. */
    public const UPDATED_AT = null;

    /**
     * BR-AT6: append-only. The ONLY process that removes a row is the BR-AT11 retention
     * sweep, which is a scheduled command with one fixed predicate — not a general delete
     * capability, and not reachable through this model.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('security_events is append-only (audit-trail.spec.md BR-AT6).');
        });

        static::deleting(function (): never {
            throw new RuntimeException('security_events is append-only: rows leave only through the BR-AT11 retention sweep, never through this model.');
        });
    }

    protected $fillable = [
        'user_id',
        'event_type',
        'identifier',
        'ip_address',
        'user_agent',
        'company_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * The account, when there is one.
     *
     * ⚠ Null is the RETENTION DISCRIMINATOR (BR-AT11): a row with a user_id is kept forever,
     * a row without one for 90 days. Never populate it defensively — a placeholder silently
     * converts a 90-day row into a permanent one.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Reporting convenience only. Never consulted to decide access. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** True when this attempt matched no account — the 90-day retention class. */
    public function isUnattributed(): bool
    {
        return $this->user_id === null;
    }
}
