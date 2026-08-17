<?php

namespace App\Services\Employee;

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\EmployeeStatusHistory;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The Status History tab's merged timeline — `employee-master.spec.md` §7, §5.3,
 * `adr/0003` decision 8.
 *
 * ⚠ THE FIRST TWO-SOURCE MERGE IN THIS CODEBASE, and it exists so the data need not be stored
 * twice. `employee_roles` already records every grant and revocation with its date, actor and
 * reason; `employee_status_history` records status, position, department, level and employer
 * changes. HR reads one history. **A writer who appends role changes to the ledger to make
 * this query simpler has created the second record of one fact that this merge exists to
 * avoid** — that is the whole warning §7 attaches to it.
 *
 * ⚠ IT LIVES IN A SERVICE, NOT ON THE MODEL AND NOT IN THE COMPONENT. `conventions.md` §1
 * puts relationships, scopes and casts on a model and forbids fat business logic there; the
 * Livewire component holds no logic by the same rule the list screen follows.
 *
 * ⚠ READ-SIDE ONLY. Nothing in this class writes, and nothing may be added that does.
 */
class StatusTimeline
{
    /**
     * How each `change_type` reads on the timeline.
     *
     * ⚠ A MAP, NOT A DERIVATION FROM THE ENUM VALUE. `STAFF_STATUS` reads "Status" in §7's own
     * example, and deriving labels from column values produces "Staff status" — close enough
     * to look deliberate and wrong against the spec. `EmployeeStatusHistory::CHANGE_TYPES` is
     * asserted against these keys by a test, so a sixth value cannot arrive unlabelled.
     */
    private const CHANGE_TYPE_LABELS = [
        'STAFF_STATUS' => 'Status',
        'POSITION' => 'Position',
        'DEPARTMENT' => 'Department',
        'LEVEL' => 'Level',
        'EMPLOYER' => 'Employer',
    ];

    /**
     * The whole history for one employee, oldest first.
     *
     * ⚠ PERMISSION WAS DECIDED ONE LEVEL UP AND IS NOT RE-ASKED HERE. Reaching this method
     * means `EmployeePolicy::viewTab(…, TAB_STATUS_HISTORY)` already said yes; filtering again
     * per row would add no security and would break the record, which is the same argument
     * `Employee::statusHistory()` makes for releasing its tenant scope.
     *
     * @return Collection<int, TimelineEntry>
     */
    public function for(Employee $employee): Collection
    {
        return $this->statusEntries($employee)
            ->merge($this->roleEntries($employee))
            ->sortBy(fn (TimelineEntry $entry) => $entry->sortKey())
            ->values();
    }

    /**
     * One entry per ledger row.
     *
     * ⚠ Read through `Employee::statusHistory()`, WHICH RELEASES TENANT SCOPE, and that is
     * load-bearing here rather than incidental. `employee_status_history.company_id` is the
     * employer frozen at the moment of the change and is never cascaded on a transfer, so
     * under the ordinary scope every pre-transfer row disappears and **the timeline appears to
     * begin on the transfer date** — fewer rows, no exception, nothing to notice. It reads as
     * somebody who joined recently.
     *
     * @return Collection<int, TimelineEntry>
     */
    private function statusEntries(Employee $employee): Collection
    {
        return $employee->statusHistory()
            ->with(['company:id,name', 'changedBy:id,name'])
            ->get()
            // ⚠ toBase(): an Eloquent collection assumes its items are models and keys
            // merge() on getKey(). These are read models, not rows.
            ->toBase()
            ->map(fn (EmployeeStatusHistory $row) => new TimelineEntry(
                date: $row->effective_date,
                source: TimelineEntry::SOURCE_STATUS_HISTORY,
                label: $this->changeTypeLabel($row->change_type).' → '.$row->new_label,
                companyName: $row->company?->name,
                actorName: $row->changedBy?->name,
                reason: $row->reason,
                sourceId: $row->id,
            ));
    }

    /**
     * ⚠ UP TO TWO ENTRIES PER ROW — a grant and, where one happened, a revocation. §7's own
     * example shows both shapes as separate lines, dated apart:
     *
     *   15 Jan 2026 · Role → Manager (AIM)
     *   08 Aug 2026 · Account (AIM) revoked
     *
     * One row, two events, two dates. Emitting a single entry per row would put the
     * revocation on the grant's date or lose it entirely, and a timeline that omits the day
     * authority ended is worse than no timeline: it reads as authority still held.
     *
     * ⚠ `withRevoked()` IS REQUIRED AND IS THE SUPPORTED WAY. `EmployeeRole` filters
     * `revoked_date IS NULL` by default — the scope that makes the safe query the one you get
     * without thinking — so the ordinary relationship cannot see the half of this history that
     * has ended. Never by removing the global scope ad hoc.
     *
     * @return Collection<int, TimelineEntry>
     */
    private function roleEntries(Employee $employee): Collection
    {
        return $employee->roles()->withRevoked()
            ->with(['company:id,name', 'assignedBy:id,name', 'revokedBy:id,name'])
            ->get()
            ->toBase()
            ->flatMap(function (EmployeeRole $role) {
                $label = str($role->role)->replace('_', ' ')->title()->toString();

                $entries = [new TimelineEntry(
                    date: $role->effective_date,
                    source: TimelineEntry::SOURCE_ROLES,
                    label: 'Role → '.$label,
                    companyName: $role->company?->name,
                    actorName: $role->assignedBy?->name,
                    reason: null,
                    sourceId: $role->id,
                )];

                if ($role->revoked_date !== null) {
                    $entries[] = new TimelineEntry(
                        date: $role->revoked_date,
                        source: TimelineEntry::SOURCE_ROLES,
                        label: $label.' revoked',
                        companyName: $role->company?->name,
                        actorName: $role->revokedBy?->name,
                        reason: $role->revoke_reason,
                        sourceId: $role->id,
                    );
                }

                return $entries;
            });
    }

    /**
     * ⚠ AN UNMAPPED CHANGE TYPE THROWS RATHER THAN FALLING BACK TO THE RAW VALUE. Same
     * reasoning as `EmployeePolicy::viewTab()` refusing an unknown tab: a missing label is a
     * programming error, and a silent fallback would ship a timeline reading "STAFF_STATUS →
     * CONFIRMED" that looks like a styling bug rather than a missing decision.
     *
     * Unreachable from user input — `change_type` is an enum column written by two Actions —
     * so this cannot be triggered from a URL the way an unknown tab can.
     */
    private function changeTypeLabel(string $changeType): string
    {
        return self::CHANGE_TYPE_LABELS[$changeType]
            ?? throw new InvalidArgumentException(
                "\"{$changeType}\" has no timeline label. Adding a change_type means deciding "
                .'how it reads on the Status History tab (employee-master.spec.md §7), not '
                .'letting the raw enum value through.'
            );
    }
}
