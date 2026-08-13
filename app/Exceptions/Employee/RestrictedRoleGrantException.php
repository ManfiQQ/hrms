<?php

namespace App\Exceptions\Employee;

use RuntimeException;

/**
 * An attempt to grant one of the four restricted roles without the authority to do it —
 * BR-16, `adr/0003` decision 3.
 *
 * ⚠ Thrown from the MODEL, not from a policy or an Action, and that placement is the whole
 * point. A policy protects the paths that remember to ask it; this fires on every write path
 * that exists or will ever exist, including one a future module writes without reading
 * BR-16 first.
 *
 * Without it, `adr/0003` decision 5 — *only `ACCOUNT` reads salary, no `HR` ever* — is
 * unenforceable: an HR who can insert an `employee_roles` row grants themselves `ACCOUNT` and
 * reads every salary in the group by lunchtime. **The rule would not be violated. It would be
 * walked around through the front door, and it would look like ordinary HR activity in the
 * audit log.**
 */
class RestrictedRoleGrantException extends RuntimeException
{
    public static function byAccount(string $role): self
    {
        return new self(
            "Only Master Admin may grant the {$role} role (BR-16, adr/0003 decision 3). "
            .'ACCOUNT, HR, ASSISTANT_DIRECTOR and HOD are hardcoded restricted: the first '
            .'three because they are self-propagating or open salary, and HOD because '
            .'granting it does not add an approval tier, it bypasses two.'
        );
    }

    /**
     * ⚠ The unauthenticated case gets its own message, because the fix is different and the
     * wrong fix is a permanent hole.
     *
     * Somebody hitting this is in a seeder, a console command, a queue job or the importer.
     * The answer is NOT to relax the guard for console contexts — that opens the door for
     * every future job at once. It is to enter RestrictedRoleContext deliberately, with a
     * reason.
     */
    public static function withoutActor(string $role): self
    {
        return new self(
            "Granting the restricted role {$role} requires an authenticated Master Admin, or "
            .'an explicit App\Services\Auth\RestrictedRoleContext::run() with a stated reason '
            .'(BR-16). Do not exempt console contexts: a runningInConsole() escape opens the '
            .'door for every seeder, command and queue job permanently, which is the ambient '
            .'bypass adr/0005 decision 5 rejects in its own domain.'
        );
    }
}
