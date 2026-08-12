<?php

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * An activation token that cannot be redeemed — auth-rbac.spec.md §5.6.
 *
 * ⚠ ONE MESSAGE FOR ALL THREE CAUSES: already used, expired, and unknown. The distinction is
 * withheld on purpose.
 *
 * Telling somebody a token has EXPIRED confirms it once existed; telling them it is UNKNOWN
 * confirms it never did. Either answer turns the activation URL into an oracle that can be
 * walked — and the reward for finding a live one is not a hint but an ACCOUNT: redemption
 * authenticates the holder outright and lets them set the password. That is the highest-value
 * unauthenticated endpoint in the system, and it is reachable by anyone with the link.
 *
 * The same reasoning as BR-A3's login message, and sharper here, because a failed login costs
 * an attacker a guess while a redeemed token costs an employee their identity in the audit
 * log.
 */
class InvalidActivationTokenException extends RuntimeException
{
    /** The single message every rejected redemption produces, whatever the cause. */
    public const MESSAGE = 'This activation link is not valid. Ask HR to send you a new one.';

    public static function make(): self
    {
        return new self(self::MESSAGE);
    }
}
