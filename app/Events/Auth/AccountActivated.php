<?php

namespace App\Events\Auth;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * BR-A21 — an employee has redeemed their activation QR.
 *
 * ⚠ NOTHING LISTENS TO THIS YET, AND THAT IS THE CURRENT STATE RATHER THAN AN OVERSIGHT.
 *
 * §5.6 requires HR to be notified on redemption, and the Notification Engine has no spec.
 * Writing a mailer or a notification class here would be inventing that module's delivery
 * rules, its channels and its recipient resolution — the code-before-spec pattern
 * Principle #1 exists to prevent, and the same reason `AccountFrozen` (BR-A16) is dispatched
 * to nobody.
 *
 * The trigger belongs here; the delivery does not.
 *
 * ⚠ What HR needs from it is the third of BR-A22's three activation states — *generated, not
 * downloaded* / *downloaded, not redeemed* / *redeemed*. A future listener supplies the last
 * one; the first two are read from `activation_downloaded_at` and this event's effect on
 * `activation_used_at`, not from anything a notification remembers.
 */
class AccountActivated
{
    use Dispatchable;

    public function __construct(public readonly User $user) {}
}
