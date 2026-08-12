<?php

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * A failed login attempt — BR-A3.
 *
 * ⚠ ONE MESSAGE FOR EVERY CAUSE. An unknown phone number, a wrong password, and a locked
 * account all produce the same text. The response must not reveal whether the username
 * exists: the username IS a phone number, so an oracle here turns "I know this person works
 * there" into "I know their login", and a hundred numbers can be probed in a minute.
 *
 * ⚠ AccountLockedException extends this and carries the SAME message deliberately. The
 * subclass exists so a future screen can decide, with the client, whether to tell the
 * employee how long to wait — that is a real usability question and a real disclosure
 * question, and it belongs to the UI work, not to this service. Until it is asked, the
 * service discloses nothing. A caller that renders getMessage() cannot leak by accident.
 */
class InvalidCredentialsException extends RuntimeException
{
    /** The single message any failed attempt produces, whatever the cause. */
    public const MESSAGE = 'Invalid credentials.';

    public static function make(): static
    {
        return new static(static::MESSAGE);
    }
}
