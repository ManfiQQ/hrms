<?php

namespace App\Support\Auth;

/**
 * The one place a Malaysian phone number is normalised — BR-A1.
 *
 * ⚠ THIS CLASS HAS EXACTLY ONE IMPLEMENTATION AND MUST KEEP HAVING ONE. BR-A1 requires the
 * login attempt and the employee form to call the same normaliser, because two of them will
 * diverge — and the divergence is silent in the worst way: an employee registered under one
 * normalisation cannot log in under the other, and the system reports "invalid credentials"
 * to someone typing the correct number.
 *
 * `012-345 6789`, `0123456789` and `+60123456789` are ONE number and must all authenticate
 * to the same account. `users.phone_no` is the login username (BR-A1, adr/0006) and carries
 * a unique index, so the value stored must already be normalised or two spellings of one
 * number would occupy two rows.
 */
class PhoneNumber
{
    /** BR-A1: 9-12 digits after normalisation. */
    public const MIN_DIGITS = 9;

    public const MAX_DIGITS = 12;

    /**
     * Strip spaces, dashes and a leading +60 or 60, leaving digits only.
     *
     * ⚠ The country prefix is stripped only when it LEADS. A number such as 0136012345
     * contains "60" in the middle, and removing it there would silently rewrite one
     * employee's number into another's — which the unique index would then report as a
     * duplicate registration, or worse, would not.
     */
    public static function normalise(string $input): string
    {
        // Everything that is not a digit or a leading plus is formatting noise.
        $digits = preg_replace('/[^0-9+]/', '', trim($input)) ?? '';

        // +60123456789 → 0123456789. The leading 0 is how the number is written locally, and
        // it is the form employees will type.
        if (str_starts_with($digits, '+60')) {
            return '0'.substr($digits, 3);
        }

        $digits = str_replace('+', '', $digits);

        // 60123456789 → 0123456789, but only when 60 leads AND what follows is not already a
        // local number starting with 0.
        if (str_starts_with($digits, '60') && ! str_starts_with($digits, '600')) {
            return '0'.substr($digits, 2);
        }

        return $digits;
    }

    /** BR-A1's length rule, applied to the NORMALISED value. */
    public static function isValid(string $normalised): bool
    {
        $length = strlen($normalised);

        return ctype_digit($normalised)
            && $length >= self::MIN_DIGITS
            && $length <= self::MAX_DIGITS;
    }
}
