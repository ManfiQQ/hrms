<?php

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * BR-A13's 3/1 limits, refused — auth-rbac.spec.md §5.8.
 *
 * ⚠ Both limits are **enforced by the system, not by policy**, and the two failures they
 * prevent are opposite in kind:
 *
 *   - A **fourth** Master Admin widens the set of accounts that bypass tenant scope and read
 *     every salary in the group. The cap is small on purpose; three is already enough for
 *     one to be unavailable.
 *   - Removing the **last** one locks everybody out of the operations only Master Admin can
 *     perform — granting restricted roles, changing `system_access`, repairing data — with no
 *     path back, because the seeder is idempotent and refuses to create a second.
 */
class MasterAdminLimitException extends RuntimeException
{
    public static function tooMany(int $maximum, int $existing): self
    {
        return new self(
            "There are already {$existing} Master Admin accounts and the limit is {$maximum}. "
            .'Remove one before creating another (auth-rbac.spec.md BR-A13).'
        );
    }

    /**
     * ⚠ The message says what to do instead, because the person hitting this is usually
     * trying to hand the role over rather than abolish it — and the safe order is create the
     * replacement first, then remove the outgoing one.
     */
    public static function lastRemaining(): self
    {
        return new self(
            'This is the only Master Admin account and it cannot be removed. Create the '
            .'replacement first, then remove this one — the seeder will not create a second '
            .'(auth-rbac.spec.md BR-A13, §5.8).'
        );
    }

    public static function notAMasterAdmin(): self
    {
        return new self('That account is not a Master Admin, so there is nothing to remove.');
    }

    /**
     * ⚠ Self-removal is refused separately from the last-one rule, because it fails for a
     * different reason and at a different time. Removing your own access mid-session leaves
     * a page that appears to work and refuses every action, and the account cannot undo it.
     */
    public static function cannotRemoveSelf(): self
    {
        return new self(
            'You cannot remove your own Master Admin access. Ask another Master Admin to do '
            .'it, so that the change is made by somebody who still has an account afterwards.'
        );
    }
}
