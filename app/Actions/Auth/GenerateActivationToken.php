<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Auth\AuthPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * BR-A21 — the single-use activation token, auth-rbac.spec.md §5.6.
 *
 * Called inside the employee-creation transaction (BR-A20), and by HR when regenerating a
 * token the employee never redeemed.
 *
 * ⚠ ACTIVATION IS A SINGLE-USE QR, NOT A TEMPORARY PASSWORD, and the difference is the whole
 * decision. The IC number was proposed as a first password and REJECTED: it is not a secret,
 * and unlike a password it can never be changed. It would open a window — lasting until first
 * login — in which anyone knowing a phone number and an IC number could enter as that person,
 * with the audit log showing the employee themselves (adr/0004 decision 7).
 *
 * ⚠ This Action GENERATES ONLY. Redemption, the QR image, and delivery are elsewhere:
 * redeeming sets `activation_used_at` and forces password creation, and serving the image
 * sets `activation_downloaded_at` (BR-A22 — the system records the download, not the send,
 * because a "mark as sent" button records an assertion and reads as a fact).
 */
class GenerateActivationToken
{
    private const VALIDITY_KEY = 'auth.activation.validity_hours';

    public function __construct(private readonly AuthPolicy $policy) {}

    /**
     * Issue a fresh token, invalidating any previous one.
     *
     * ⚠ Regeneration CLEARS both timestamps as well as replacing the token. Leaving
     * `activation_downloaded_at` set would show HR a token as already fetched when the one
     * in play has never been seen, and leaving `activation_used_at` set would mark a live
     * token as spent (§5.6).
     */
    public function execute(User $user): string
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'Refusing to issue an activation token outside a transaction. On creation it '
                .'must roll back with the account (BR-A20); on regeneration it must not '
                .'invalidate the previous token unless the new one lands.'
            );
        }

        // Random, not derived. Anything computed from the employee — IC, phone, employee_no
        // — would be guessable by whoever already knows those, which is precisely the window
        // adr/0004 decision 7 closed.
        $token = Str::random(64);

        $user->forceFill([
            'activation_token' => $token,
            'activation_expires_at' => now()->addHours($this->policy->int(self::VALIDITY_KEY, $user)),
            'activation_downloaded_at' => null,
            'activation_used_at' => null,
        ])->save();

        return $token;
    }
}
