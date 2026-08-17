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

// ─── adr/0015 — the four unique indexes are functional, not plain ─────────────────────────────

/**
 * ⚠ ASSERTED AGAINST `information_schema`, NOT THE MIGRATION SOURCE, for the same reason the test
 * above reads SHOW INDEX: a test that grepped the migration would pass on a file that never ran.
 *
 * ⚠ AND THE EXPRESSION IS ASSERTED, NOT ONLY THE INDEX NAME. This is the whole point. The
 * rejected composite `UNIQUE (ic_no, superseded_at)` is created successfully, would satisfy a
 * name-only check, and REMOVES THE CONSTRAINT ENTIRELY — two live rows both carrying NULL are
 * distinct, so both are accepted. It reads as a narrowing and it is a cancellation. A test that
 * only counted indexes could not tell the two apart.
 *
 * MySQL normalises the expression it stores, so the comparison strips backticks and whitespace
 * rather than matching the migration's literal text.
 */
function uniqueIndexExpressions(string $table): array
{
    $rows = DB::select(
        'SELECT INDEX_NAME, EXPRESSION FROM information_schema.STATISTICS '
        .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0',
        [$table]
    );

    // ⚠ conventions.md §9 — a guard over a collection must assert the collection is not empty,
    // or a query returning nothing satisfies every filter below by iterating zero times.
    expect($rows)->not->toBeEmpty();

    $expressions = [];

    foreach ($rows as $row) {
        if ($row->EXPRESSION === null) {
            continue;
        }

        $expressions[$row->INDEX_NAME] = strtolower(
            preg_replace('/[`\s]/', '', $row->EXPRESSION)
        );
    }

    return $expressions;
}

it('scopes the three employee identity indexes to live rows only', function () {
    $expressions = uniqueIndexExpressions('employees');

    foreach (['ic_no', 'passport_no', 'fingerprint_id'] as $column) {
        expect($expressions)->toHaveKey("employees_{$column}_live_unique");

        expect($expressions["employees_{$column}_live_unique"])
            ->toBe("if((superseded_atisnull),{$column},null)");
    }
});

it('scopes the login username index to live accounts only', function () {
    $expressions = uniqueIndexExpressions('users');

    expect($expressions)->toHaveKey('users_phone_no_live_unique')
        ->and($expressions['users_phone_no_live_unique'])
        ->toBe('if((superseded_atisnull),phone_no,null)');
});

/**
 * ⚠ `employee_no` IS DELIBERATELY NOT SCOPED, AND ITS ABSENCE IS THE DECISION. A number is never
 * reissued and a rejoiner is given a NEW one (`adr/0003` decision 9, BR-13), so it has no rejoiner
 * problem to solve — and scoping it would release a number that must stay retired for ever,
 * pointing previously printed letters and payslips at the wrong person.
 *
 * ⚠ SAME FOR `email` AND `activation_token` ON `users`. Neither is an identity a rejoiner brings
 * back: email authenticates nothing (`adr/0006`), and an activation token dies on redemption
 * (BR-A21).
 */
it('leaves employee_no, email and activation_token as plain unique indexes', function () {
    expect(uniqueIndexExpressions('employees'))->not->toHaveKey('employees_employee_no_live_unique');

    $users = uniqueIndexExpressions('users');

    expect($users)->not->toHaveKey('users_email_live_unique')
        ->and($users)->not->toHaveKey('users_activation_token_live_unique');

    // The plain indexes are still there — an unscoped index carries no expression at all, so
    // their absence from the map above is what proves it.
    $plain = collect(DB::select('SHOW INDEX FROM employees'))->pluck('Key_name')->unique();

    expect($plain)->toContain('employees_employee_no_unique');
});
