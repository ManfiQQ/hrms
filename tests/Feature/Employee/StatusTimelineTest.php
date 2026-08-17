<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\EmployeeStatusHistory;
use App\Models\User;
use App\Services\Employee\StatusTimeline;
use App\Services\Employee\TimelineEntry;

/**
 * The merged Status History timeline — `employee-master.spec.md` §7, `adr/0003` decision 8.
 *
 * ⚠ THE FIRST TWO-SOURCE MERGE IN THIS CODEBASE, so there is no existing shape to lean on and
 * every semantic below is asserted rather than assumed. The five that matter: two entries per
 * revoked role row, one per ledger row, a deterministic tiebreak, a company name on every
 * entry, and pre-transfer rows surviving the tenant scope.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

    $this->actingAs(User::factory()->masterAdmin()->create());

    $this->timeline = app(StatusTimeline::class);
    $this->employee = Employee::factory()->forCompany($this->aim)->create();
});

function ledgerRow(Employee $employee, Company $at, string $date, array $attributes = []): EmployeeStatusHistory
{
    return EmployeeStatusHistory::factory()->create($attributes + [
        'employee_id' => $employee->id,
        'company_id' => $at->id,
        'effective_date' => $date,
    ]);
}

function roleRow(Employee $employee, Company $at, string $role, string $granted, ?string $revoked = null): EmployeeRole
{
    return EmployeeRole::factory()->forCompany($at)->role($role)->create([
        'employee_id' => $employee->id,
        'effective_date' => $granted,
        'revoked_date' => $revoked,
    ]);
}

/**
 * ⚠ §7's own example shows both shapes as separate dated lines. A single entry per row would
 * put the revocation on the grant's date or lose it — and a timeline that omits the day
 * authority ended reads as authority still held.
 */
it('emits two entries for a revoked role row, on its two dates', function () {
    roleRow($this->employee, $this->aim, 'MANAGER', '2026-01-15', '2026-08-08');

    $entries = $this->timeline->for($this->employee);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->date->toDateString())->toBe('2026-01-15')
        ->and($entries[0]->label)->toBe('Role → Manager')
        ->and($entries[1]->date->toDateString())->toBe('2026-08-08')
        ->and($entries[1]->label)->toBe('Manager revoked');
});

it('emits one entry for a role still held', function () {
    roleRow($this->employee, $this->aim, 'SUPERVISOR', '2026-03-01');

    expect($this->timeline->for($this->employee))->toHaveCount(1);
});

/**
 * ⚠ The revoked half is only reachable through `withRevoked()`. `EmployeeRole` filters
 * `revoked_date IS NULL` by default, so the ordinary relationship cannot see the history that
 * has ended — half the timeline, missing with nothing to notice.
 */
it('includes roles that are no longer held at all', function () {
    roleRow($this->employee, $this->aim, 'ACCOUNT', '2026-02-01', '2026-04-01');

    expect($this->employee->roles()->count())->toBe(0)
        ->and($this->timeline->for($this->employee))->toHaveCount(2);
});

it('emits one entry per ledger row, labelled by change type', function () {
    ledgerRow($this->employee, $this->aim, '2026-03-01', ['change_type' => 'STAFF_STATUS', 'new_label' => 'CONFIRMED']);
    ledgerRow($this->employee, $this->aim, '2026-04-01', ['change_type' => 'DEPARTMENT', 'new_label' => 'Logistics']);

    $entries = $this->timeline->for($this->employee);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->label)->toBe('Status → CONFIRMED')
        ->and($entries[1]->label)->toBe('Department → Logistics');
});

it('orders the merged list oldest first across both sources', function () {
    ledgerRow($this->employee, $this->aim, '2026-03-01', ['new_label' => 'CONFIRMED']);
    roleRow($this->employee, $this->aim, 'MANAGER', '2026-01-15', '2026-08-08');

    expect($this->timeline->for($this->employee)->map(fn (TimelineEntry $e) => $e->date->toDateString())->all())
        ->toBe(['2026-01-15', '2026-03-01', '2026-08-08']);
});

/**
 * ⚠ THE TIEBREAK, AND IT IS NOT COSMETIC. `effective_date` is a DATE, so two events on one day
 * are ordinary rather than rare. Without a decided order they appear in whatever order the
 * database returned them — stable until it is not, and a timeline that reorders itself between
 * page loads is one nobody can cite.
 */
it('breaks a same-day tie with status history first, then roles, then id', function () {
    $sameDay = '2026-05-05';

    $secondRole = roleRow($this->employee, $this->aim, 'SUPERVISOR', $sameDay);
    $firstRole = roleRow($this->employee, $this->aim, 'MANAGER', $sameDay);

    // ⚠ THE LEDGER'S ID IS FORCED ABOVE THE ROLES', AND WITHOUT THIS THE TEST PROVES NOTHING.
    //
    // The two tables have INDEPENDENT id sequences. Left to chance the same-day ledger row and
    // a role row can share an id, their sort keys become identical once the rank is removed,
    // and a stable sort keeps them in merge order — which puts status history first anyway. So
    // the test passed against a rank map that had been deleted.
    //
    // Found 2026-08-17 by running that break, getting GREEN, and then confirming the break was
    // in place rather than concluding the guard was sound (conventions.md §9). Forcing the
    // ledger id HIGHER means id order would put the roles first, so the only thing that can
    // produce the expected order is the rank map itself.
    //
    // A loop rather than a fixed count because MySQL does not rewind auto-increment on a
    // rolled-back test, so the gap between the two sequences differs per run.
    $burned = 0;

    while (EmployeeStatusHistory::withoutGlobalScopes()->max('id') <= $firstRole->id) {
        ledgerRow($this->employee, $this->aim, '2020-01-01', ['new_label' => 'ACTIVE']);
        $burned++;
    }

    $sameDayLedger = ledgerRow($this->employee, $this->aim, $sameDay, ['new_label' => 'CONFIRMED']);

    // The role rows are created in reverse id order on purpose: an implementation that
    // happened to return them in insertion order would pass a weaker test.
    expect($firstRole->id)->toBeGreaterThan($secondRole->id)
        ->and($sameDayLedger->id)->toBeGreaterThan($firstRole->id);

    // The burned rows are all dated 2020 and sort ahead of everything under test.
    $entries = $this->timeline->for($this->employee)->skip($burned)->values();

    expect($entries->map(fn (TimelineEntry $e) => $e->source)->all())->toBe([
        TimelineEntry::SOURCE_STATUS_HISTORY,
        TimelineEntry::SOURCE_ROLES,
        TimelineEntry::SOURCE_ROLES,
    ])->and($entries[1]->sourceId)->toBe($secondRole->id)
        ->and($entries[2]->sourceId)->toBe($firstRole->id);
});

/**
 * ⚠ EVERY ENTRY NAMES ITS COMPANY, and this is the case that shows why. The employee works at
 * AIM today; the ledger row is frozen at AHS, which is where the event happened. A timeline
 * without the label attributes an old employer's event to the current one.
 */
it('carries the company name on every entry, from both sources', function () {
    ledgerRow($this->employee, $this->ahs, '2026-02-01', ['new_label' => 'CONFIRMED']);
    roleRow($this->employee, $this->aim, 'MANAGER', '2026-06-01');

    $entries = $this->timeline->for($this->employee);

    expect($entries->every(fn (TimelineEntry $e) => $e->companyName !== null))->toBeTrue()
        ->and($entries[0]->companyName)->toBe($this->ahs->name)
        ->and($entries[1]->companyName)->toBe($this->aim->name);
});

/**
 * ⚠ THE TENANT-SCOPE RELEASE, WITHOUT WHICH THE HISTORY APPEARS TO BEGIN ON THE TRANSFER DATE.
 * `employee_status_history.company_id` is frozen and never cascaded, so an ordinary scoped
 * read drops every pre-transfer row: fewer rows, no exception, and it reads as somebody who
 * joined recently.
 */
it('keeps pre-transfer ledger rows, which carry a former employer', function () {
    ledgerRow($this->employee, $this->ahs, '2026-01-01', ['new_label' => 'ACTIVE']);
    ledgerRow($this->employee, $this->aim, '2026-07-01', ['new_label' => 'CONFIRMED']);

    // Read as an AIM-scoped account: the pre-transfer row belongs to a company this reader
    // cannot otherwise see, and it must still appear on this employee's own history.
    $aimHrEmployee = Employee::factory()->forCompany($this->aim)->create();
    EmployeeRole::factory()->forCompany($this->aim)->role('HR')->create(['employee_id' => $aimHrEmployee->id]);
    $this->actingAs(User::factory()->forEmployee($aimHrEmployee)->create());

    expect($this->timeline->for($this->employee)->pluck('companyName')->all())
        ->toBe([$this->ahs->name, $this->aim->name]);
});

/**
 * ⚠ Same shape as the PERSONAL_LABELS guard: a value added to the enum without a timeline
 * label must fail here, not on the first reader who opens the tab.
 */
it('has a timeline label for every change type, and no orphans', function () {
    $labelled = array_keys((fn () => self::CHANGE_TYPE_LABELS)->call($this->timeline));

    expect(EmployeeStatusHistory::CHANGE_TYPES)->not->toBeEmpty()
        ->and(array_diff(EmployeeStatusHistory::CHANGE_TYPES, $labelled))->toBe([])
        ->and(array_diff($labelled, EmployeeStatusHistory::CHANGE_TYPES))->toBe([]);
});
