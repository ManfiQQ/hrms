<?php

namespace App\Events\Auth;

use App\Models\Employee;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * BR-A16 — an account has been frozen by a terminal status change.
 *
 * ⚠ NOTHING LISTENS TO THIS YET, AND THAT IS THE CURRENT STATE RATHER THAN AN OVERSIGHT.
 *
 * The Approval Engine is what must act on it: anything awaiting the frozen person's
 * endorsement or approval **escalates to `HR`**, marked as having skipped that stage because
 * the approver is frozen. That module has no spec, so writing a listener now would be
 * inventing the routing rules Principle #1 exists to keep out of code.
 *
 * The trigger belongs here; the routing does not. auth-rbac.spec.md BR-A16 draws that line
 * explicitly, and this event is the seam it describes.
 *
 * ⚠ Automatic reassignment to a substitute manager was REJECTED, so a future listener must
 * not add it: a three-person department may have no substitute, and a system that picks an
 * approver creates a question of responsibility nobody asked it to answer. Escalation reuses
 * the existing `APPROVED_BY_HR` path with a new trigger — not "HR chose to step in" but
 * "there is no one else".
 */
class AccountFrozen
{
    use Dispatchable;

    public function __construct(
        public readonly Employee $employee,
        public readonly string $status,
    ) {}
}
