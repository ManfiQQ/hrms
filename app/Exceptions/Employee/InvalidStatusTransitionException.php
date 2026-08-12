<?php

namespace App\Exceptions\Employee;

use RuntimeException;

/**
 * A status change BR-2 does not permit.
 *
 * ⚠ Thrown from the SERVICE LAYER, not from a FormRequest or a controller
 * (employee-master.spec.md BR-2: "permitted transitions are enforced in the service layer,
 * not the UI"). A rule enforced only at the edge is one an importer, a seeder, a console
 * command or a future API route walks straight past — and the legacy system's status field
 * was a free column that anything could set to anything.
 */
class InvalidStatusTransitionException extends RuntimeException
{
    public static function between(string $from, string $to): self
    {
        return new self("An employee cannot move from {$from} to {$to} (employee-master.spec.md BR-2).");
    }

    /**
     * ⚠ The terminal case gets its own message, because the fix is completely different.
     *
     * RESIGNED and TERMINATED are terminal: reinstatement is a NEW employee record
     * referencing the old one, not a status flip back (BR-2, adr/0003 decision 9). Somebody
     * hitting this wants the rejoiner path, not a different transition — and there is no
     * reactivation, by anyone, including Master Admin (BR-A18).
     */
    public static function fromTerminal(string $from, string $to): self
    {
        return new self(
            "{$from} is terminal and cannot become {$to}. A returning employee gets a NEW "
            .'record with a new employee_no, linked by previous_employee_id — never a '
            .'reactivated one (BR-2, BR-A18, adr/0003 decision 9).'
        );
    }
}
