<?php

namespace App\Exceptions\Auth;

use Carbon\CarbonInterface;

/**
 * A login attempt against a throttled account — BR-A3.
 *
 * ⚠ It carries the SAME message as its parent, and that is the point: the caller cannot leak
 * the account's existence by rendering getMessage(). The distinct type exists so that a
 * future login screen can make a deliberate decision — with the client — about whether to
 * tell the employee how long to wait, which is a usability question and a disclosure
 * question at once. Until that decision is taken, this service discloses nothing.
 *
 * The lock details are available to a caller that asks for them explicitly, which is the
 * difference between a considered disclosure and an accidental one.
 */
class AccountLockedException extends InvalidCredentialsException
{
    public ?CarbonInterface $lockedUntil = null;

    public bool $permanent = false;

    public static function timed(CarbonInterface $until): self
    {
        $e = new self(self::MESSAGE);
        $e->lockedUntil = $until;

        return $e;
    }

    /**
     * The fourth tier. Only `HR` or Master Admin may lift it (BR-A7) — there is no
     * self-service path and no automatic expiry, because at twelve failures the likeliest
     * explanation is no longer a typo.
     */
    public static function permanent(): self
    {
        $e = new self(self::MESSAGE);
        $e->permanent = true;

        return $e;
    }
}
