<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * HR reissues an activation QR — auth-rbac.spec.md §5.6, §7, BR-A21.
 *
 * ⚠ REGENERATION INVALIDATES THE PREVIOUS TOKEN AND CLEARS BOTH TIMESTAMPS. Leaving
 * `activation_downloaded_at` set would show HR a token as already fetched when the one in
 * play has never been seen; leaving `activation_used_at` set would mark a live token as
 * spent. Both would corrupt the three activation states the screen reads (BR-A22).
 *
 * ⚠ Separate from `GenerateActivationToken`, which issues the first one inside the
 * employee-creation transaction. That path is already accounted for by `employee.created`;
 * auditing inside the shared Action would record every registration twice. This is the
 * administrative operation, and it is the one worth a row of its own — a reissued token is a
 * fresh chance to take possession of somebody's account.
 */
class RegenerateActivationToken
{
    /**
     * ⚠ `activation_expires_at`, NEVER `activation_token`. The token is a credential:
     * whoever holds it can activate the account, and `audit_logs` is readable by `HR` and
     * `ASSISTANT_DIRECTOR` within their read scope (BR-AT9). Writing it there would hand a
     * live credential to every reader of the audit screen — including the role that is
     * deliberately barred from the activation image itself.
     */
    public const AUDITS = [
        User::class => ['activation_expires_at'],
    ];

    public function __construct(
        private readonly GenerateActivationToken $generate,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $user, ?string $reason = null): string
    {
        return DB::transaction(function () use ($user, $reason) {
            $previousExpiry = $user->activation_expires_at;
            $wasDownloaded = $user->activation_downloaded_at !== null;

            $token = $this->generate->execute($user);

            $this->audit->record(
                action: 'account.activation_regenerated',
                subject: $user,
                field: 'activation_expires_at',
                old: $previousExpiry?->toDateTimeString(),
                new: $user->activation_expires_at?->toDateTimeString(),
                reason: $reason ?? ($wasDownloaded
                    ? 'Activation reissued; the previous token had been downloaded and is now dead.'
                    : 'Activation reissued; the previous token was never downloaded.'),
            );

            return $token;
        });
    }
}
