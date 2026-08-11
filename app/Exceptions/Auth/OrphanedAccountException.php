<?php

namespace App\Exceptions\Auth;

use App\Models\User;
use RuntimeException;

/**
 * A STANDARD account that cannot be resolved to an employer.
 *
 * This state is IMPOSSIBLE under BR-A20: every account other than Master Admin and Director
 * is created in the same transaction as its employee record, and the two account types that
 * legitimately have no employee are FULL and VIEW_ONLY. Reaching this exception means the
 * data is already corrupt.
 *
 * ⚠ Why this throws instead of resolving to an empty scope.
 *
 * An empty scope is a valid, ordinary answer — it renders as an empty list, and the user
 * reads it as "there is no data yet". The cause would be an account that should not exist,
 * and nothing anywhere would say so. The person affected cannot tell the difference, and
 * neither can anyone they report it to.
 *
 * This follows the pattern adr/0001 established for Master Admin: an impossible state is
 * held out at the boundary with a clear reason rather than handled quietly. Where a
 * condition cannot legitimately arise, code that silently accommodates it converts a data
 * fault into a user-facing mystery.
 *
 * Recorded in auth-rbac.spec.md §5.4, which did not previously cover this case.
 */
class OrphanedAccountException extends RuntimeException
{
    public static function missingEmployee(User $user): self
    {
        return new self(
            "User {$user->getKey()} has system_access = STANDARD but no employee record. "
            .'Every account other than Master Admin and Director is created in the same '
            .'transaction as its employee (BR-A20), so this account is corrupt. Read scope '
            .'cannot be derived and must not be guessed.'
        );
    }

    public static function missingEmployer(User $user): self
    {
        return new self(
            "User {$user->getKey()} has an employee record whose employer cannot be loaded. "
            .'employees.company_id is NOT NULL, so the company is missing or soft-deleted. '
            .'Read scope derives from the employer\'s position in the company hierarchy and '
            .'cannot be derived without it.'
        );
    }
}
