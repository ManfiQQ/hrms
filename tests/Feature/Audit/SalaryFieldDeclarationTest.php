<?php

use App\Support\Audit\SalaryFields;
use Illuminate\Support\Facades\Schema;

/**
 * The architecture guard for BR-AT10 — adr/0005 decision 6's pattern, adopted deliberately
 * rather than by analogy.
 *
 * ⚠ The stakes. A Payroll table whose money column is never declared leaks every salary
 * change in the group to HR, through a screen labelled "Audit Log", and NOTHING ERRORS. The
 * reader cannot infer salariness from an audit row — the row holds a column name and two
 * strings — so the declaration is the only thing standing in the way, and deny-by-default is
 * not available.
 *
 * That ADR chose a guard test over review for the same reason it applies here: the tables
 * most at risk have not been written. Phase 2's Payroll will be built by someone who has
 * this spec available but no reason to open it, because nothing in the act of writing
 * Schema::create prompts them to.
 */

/** Column types that hold money. A salary column is never a varchar. */
const MONEY_COLUMN_TYPES = ['decimal', 'newdecimal', 'float', 'double'];

/** @return array<class-string, list<string>> model => money-bearing column names */
function modelsWithMoneyColumns(): array
{
    $found = [];

    foreach (SalaryFields::modelClasses() as $class) {
        $table = (new $class())->getTable();

        if (! Schema::hasTable($table)) {
            continue;
        }

        $money = [];

        foreach (Schema::getColumns($table) as $column) {
            if (in_array(strtolower($column['type_name'] ?? ''), MONEY_COLUMN_TYPES, true)) {
                $money[] = $column['name'];
            }
        }

        if ($money !== []) {
            $found[$class] = $money;
        }
    }

    return $found;
}

/**
 * ⚠ The guard against a guard that checks nothing.
 *
 * No table carries a money column today, so every assertion below would be vacuous. An
 * architecture test over an empty set passes forever while checking nothing — the kind of
 * test nobody notices has died.
 */
it('refuses to pass with no money-bearing table unless that emptiness is declared', function () {
    $moneyModels = modelsWithMoneyColumns();

    if ($moneyModels !== []) {
        expect(defined(SalaryFields::class.'::NO_MONEY_TABLES_UNTIL'))->toBeFalse(
            'A money-bearing table now exists, so NO_MONEY_TABLES_UNTIL must be deleted — '.
            'leaving it would let a later emptying of the set pass unnoticed.'
        );

        return;
    }

    expect(defined(SalaryFields::class.'::NO_MONEY_TABLES_UNTIL'))->toBeTrue(
        'No table carries a money column and nothing says why, so this file asserts '.
        'nothing. Declare NO_MONEY_TABLES_UNTIL saying which module ends it (BR-AT10).'
    );

    expect(constant(SalaryFields::class.'::NO_MONEY_TABLES_UNTIL'))->toBeString()->not->toBe('');
});

it('requires every model over a money-bearing table to declare its salary fields', function () {
    $undeclared = [];

    foreach (modelsWithMoneyColumns() as $class => $moneyColumns) {
        // SALARY_FIELDS = [] is a valid answer — "this money column is not salary" — but it
        // must be SAID. Silence and "considered and none" must stay distinguishable, which
        // is the whole value of a declaration guard.
        if (! defined($class.'::SALARY_FIELDS')) {
            $undeclared[] = $class.' ('.implode(', ', $moneyColumns).')';
        }
    }

    expect($undeclared)->toBe([], sprintf(
        "These models sit over a money-bearing table but declare no SALARY_FIELDS: %s.\n".
        'Declare the salary-bearing columns, or an empty array if none of them is salary. '.
        'Without it, every audited change to those columns is readable by HR (BR-AT10).',
        implode(', ', $undeclared)
    ));
});

it('accepts only real columns in a SALARY_FIELDS declaration', function () {
    // A typo silently declares nothing: the filter matches on the field name recorded in the
    // audit row, so a misspelt declaration filters no rows at all and looks correct.
    expect(SalaryFields::modelClasses())->not->toBeEmpty();

    foreach (SalaryFields::modelClasses() as $class) {
        if (! defined($class.'::SALARY_FIELDS')) {
            continue;
        }

        $table = (new $class())->getTable();

        foreach (constant($class.'::SALARY_FIELDS') as $field) {
            expect(Schema::hasColumn($table, $field))->toBeTrue(
                "{$class}::SALARY_FIELDS names {$field}, which is not a column on {$table}."
            );
        }
    }
});
