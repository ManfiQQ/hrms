<?php

namespace App\Services\Employee;

use Illuminate\Support\Carbon;

/**
 * One line of the Status History timeline — `employee-master.spec.md` §7.
 *
 * ⚠ A READ MODEL, NOT A ROW. Two tables with different shapes are merged into one list
 * (`adr/0003` decision 8), and handing the view a mixed collection of `EmployeeStatusHistory`
 * and `EmployeeRole` objects would make every Blade line ask which kind it had. The whole
 * value of the merge is that the reader sees one history; that has to be true of the code
 * reading it as well as of the person.
 *
 * ⚠ NOTHING HERE IS PERSISTED, AND NOTHING MAY BE. The merge is a read-side concern
 * (§7, §5.3), and the reason is written where a writer will meet it: `employee_roles` already
 * records every grant and revocation with its date, actor and reason, so writing the same
 * event into `employee_status_history` to make this query simpler creates two records of one
 * fact that can disagree.
 */
final readonly class TimelineEntry
{
    public const SOURCE_STATUS_HISTORY = 'employee_status_history';

    public const SOURCE_ROLES = 'employee_roles';

    /**
     * The tiebreak, as a RANK MAP rather than a comparison scattered through a sort closure.
     *
     * ⚠ `effective_date` and `revoked_date` are DATES, not timestamps, so two events on one
     * day are the ordinary case rather than a rare collision — a role granted on the day a
     * status changed shares a sort key with it. Without a decided order the two would appear
     * in whichever order the database returned them, which is stable until it is not, and a
     * timeline that reorders itself between page loads is one nobody can cite.
     *
     * Status history first: it is the employment fact, and the role change is what follows
     * from it more often than the reverse.
     */
    public const SOURCE_RANK = [
        self::SOURCE_STATUS_HISTORY => 0,
        self::SOURCE_ROLES => 1,
    ];

    public function __construct(
        public Carbon $date,
        public string $source,
        public string $label,
        /**
         * ⚠ CARRIED ON EVERY ENTRY, INCLUDING STATUS ROWS — and §7's example does not do this.
         *
         * That example shows the company inside the role label (*"Role → Manager (AIM)"*) and
         * omits it from the status line entirely. The two sources reach this list under two
         * different scope rules — `employee_status_history` freezes the employer at the time
         * and releases tenant scope to stay readable after a transfer, while `employee_roles`
         * carries no tenant scope at all — so a transferred employee's timeline genuinely
         * spans two employers. A line without its company would silently attribute an old
         * employer's event to the current one, and the status rows are exactly the ones that
         * freeze it.
         */
        public ?string $companyName,
        public ?string $actorName,
        public ?string $reason,
        public int $sourceId,
    ) {}

    /**
     * date asc → status history before roles → id asc. Deterministic on every axis, so the
     * same data renders in the same order on every request.
     */
    public function sortKey(): string
    {
        return sprintf(
            '%s-%d-%010d',
            $this->date->format('Y-m-d'),
            self::SOURCE_RANK[$this->source],
            $this->sourceId,
        );
    }
}
