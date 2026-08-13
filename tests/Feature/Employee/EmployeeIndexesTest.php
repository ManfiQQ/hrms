<?php

use Illuminate\Support\Facades\DB;

/**
 * The composite index `employee-master.spec.md` §3 requires on `employees` — asserted
 * against the database's own index metadata, not against the migration file.
 *
 * ⚠ THIS TEST EXISTS BECAUSE THE SUITE RUNNING MIGRATIONS IS NOT A TEST OF THEM.
 * Before it was written, `$table->index(['company_id', 'staff_status'])` could be deleted
 * outright and all 351 tests still passed: nothing read the index, so nothing missed it. The
 * spec required it from the start and it was simply never written — for two days, with a
 * green suite the whole time. That is the empty guard `conventions.md` §9 describes, in its
 * purest form: not a wrong assertion, but no assertion at all.
 *
 * The failure it guards is invisible by construction. A missing index changes no result, no
 * return value and no error — only the query plan. It surfaces as "the employee list got
 * slow" months later, on a table too large to add the index to casually, and `CLAUDE.md` §3
 * forbids the shortcut that usually follows (deleting rows for performance): where reads get
 * slow the answer is an index, so the index has to be right at creation.
 *
 * It reads SHOW INDEX rather than the migration source for the same reason: a test that
 * greps the migration would pass on a file that never ran, and would be defeated by the
 * commented-out declaration `conventions.md` §9's third example was defeated by.
 */
it('carries the (company_id, staff_status) composite index the default employee list reads', function () {
    $rows = collect(DB::select('SHOW INDEX FROM employees'));

    // ⚠ conventions.md §9: a guard over a collection must assert the collection is not
    // empty. Without this line, a SHOW INDEX returning nothing at all — wrong connection,
    // table absent, driver change — would satisfy every filter below by iterating zero
    // times, and the guard would pass while guarding nothing.
    expect($rows)->not->toBeEmpty();

    // Column order is part of the index, not a detail of it. (staff_status, company_id)
    // would not serve the default list read, which narrows by the account's read scope
    // first and filters by status second, so the columns are compared as an ordered list.
    $byName = $rows
        ->groupBy('Key_name')
        ->map(fn ($columns) => $columns->sortBy('Seq_in_index')->pluck('Column_name')->all());

    expect($byName->contains(['company_id', 'staff_status']))->toBeTrue(
        'employees is missing the (company_id, staff_status) composite index required by '.
        'employee-master.spec.md §3. Indexes actually present: '.
        $byName->map(fn ($columns, $name) => $name.' ('.implode(', ', $columns).')')
            ->implode('; ')
    );
});
