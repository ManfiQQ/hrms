<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `superseded_at` on `employees`, and three unique indexes rebuilt over an expression —
 * `adr/0015` decisions 2 and 3.
 *
 * ⚠ WHAT THIS UNBLOCKS: a rejoining employee could not be registered at all. `adr/0003`
 * decision 9 gives a rejoiner a NEW record with a NEW `employee_no`; they bring the SAME
 * `ic_no`, because a person has one; `adr/0013` decision 1 made that column unique; and a
 * soft-deleted old record still occupies the index, which knows nothing about `deleted_at`.
 * Two Accepted ADRs, neither wrong alone, jointly impossible — the `adr/0006` and `adr/0007`
 * shape a third time.
 *
 * ⚠ A FORWARD MIGRATION, AND `conventions.md` §11 WAS AVAILABLE AND DELIBERATELY NOT USED.
 * All three conditions still hold — no production, no real data, one developer — so
 * `2026_08_11_100400` and `2026_08_14_100100` could have been edited at zero debt. `adr/0015`
 * decision 3 rules that out in its own words: **the columns are not being corrected, the
 * constraint is being redefined.** Editing the creating migrations would date this to 11 and 14
 * August and erase the day on which the contradiction was found and argued. There is no fourth
 * entry in the §11 usage log, and that absence is a choice.
 *
 * ⚠ MySQL DDL IS NOT TRANSACTIONAL. Every statement below auto-commits, so a failure part way
 * through leaves the table in a state no rollback undoes automatically — three indexes dropped
 * and one recreated is a table with no uniqueness on two identity columns. That is why `down()`
 * checks before it touches anything, and why it creates before it drops. Read both notes there
 * before changing the order here.
 *
 * ⚠ `employee_no` IS NOT AMONG THE REBUILT INDEXES, and its absence is the decision. It is
 * never reissued and a rejoiner is given a new one (`adr/0003` decision 9, BR-13), so it has no
 * rejoiner problem to solve. Rebuilding it would release a number that must stay retired.
 */
return new class extends Migration
{
    /**
     * ⚠ The columns whose uniqueness is now scoped to live rows.
     *
     * `fingerprint_id` is here although it is NOT an identity column — it is a device id typed
     * in from the NGTime export, unique for an entirely different reason. A rejoiner
     * re-enrolling on the same reader hits the same wall, so it takes the same shape
     * (`adr/0015` decision 3).
     */
    private const SCOPED_COLUMNS = ['ic_no', 'passport_no', 'fingerprint_id'];

    public function up(): void
    {
        // ⚠ THE COLUMN FIRST. The index expressions below reference `superseded_at`, so MySQL
        // refuses to create them while it does not exist.
        Schema::table('employees', function (Blueprint $table) {
            // ⚠ NOT A SOFT DELETE, AND IT MUST NOT BE READ AS ONE (adr/0015 decision 2). A
            // superseded record is fully present, fully readable, and still the answer to every
            // question about the employment it describes. Only its CLAIM on the identity values
            // is released. `deleted_at` already exists on this table and means something else.
            //
            // ⚠ IT RECORDS A FACT RATHER THAN DERIVING ONE. "Superseded" is not computable from
            // a terminal `staff_status` or from account expiry: AccountExpiry reads the latest
            // terminal ledger row and adds a configured window, and an index cannot compute
            // anything at all. It needs a value it can read off the row.
            //
            // Placed beside `previous_employee_id` because the two are one mechanism — that
            // column says which record replaced this one, this column says that one did.
            $table->timestamp('superseded_at')->nullable()->after('previous_employee_id');
        });

        // ⚠ THE PLAIN UNIQUE INDEXES GO. Names verified against information_schema rather than
        // assumed from Laravel's convention, because dropping the wrong name fails loudly and
        // dropping none at all fails silently.
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_ic_no_unique');
            $table->dropUnique('employees_passport_no_unique');
            $table->dropUnique('employees_fingerprint_id_unique');
        });

        // ⚠ RAW SQL, BECAUSE THE SCHEMA BUILDER CANNOT EXPRESS THIS. Blueprint::unique() takes
        // COLUMN NAMES and quotes them as identifiers, so it would emit a backtick-wrapped
        // `IF(superseded_at IS NULL, ic_no, NULL)` as though that were a column and fail. There
        // is no expression-index API in Laravel 13.
        //
        // ⚠ THE DOUBLE PARENTHESES ARE REQUIRED. MySQL reads `(expr)` as a column list and
        // `((expr))` as a functional key part. Removing the inner pair is a syntax error, not a
        // silently different index.
        //
        // ⚠ WHY IT WORKS: MySQL treats every NULL in a unique index as distinct from every
        // other, so a superseded row indexes to NULL and stops competing, while live rows are
        // constrained exactly as before. Verified on MySQL 8.4.11 against this project:
        // superseded + live sharing an IC is ALLOWED, two LIVE rows sharing one is REFUSED.
        //
        // ⚠ THE COMPOSITE ALTERNATIVE WAS TESTED AND IT CANCELS THE CONSTRAINT.
        // `UNIQUE (ic_no, superseded_at)` is created successfully and then accepts two LIVE rows
        // with the same IC, because both carry NULL and NULLs are distinct. It reads as a
        // narrowing and it is a cancellation. A partial index — `UNIQUE (ic_no) WHERE ...` — is
        // a syntax error; MySQL has no filtered indexes, that is PostgreSQL.
        //
        // ⚠ THE NAME CARRIES `_live_` ON PURPOSE. What these indexes constrain is live rows
        // only, and the old name would now describe something the index no longer does.
        foreach (self::SCOPED_COLUMNS as $column) {
            DB::statement(
                "CREATE UNIQUE INDEX employees_{$column}_live_unique "
                ."ON employees ((IF(superseded_at IS NULL, {$column}, NULL)))"
            );
        }
    }

    /**
     * ⚠ THIS ROLLBACK CAN LEGITIMATELY REFUSE TO RUN, AND THAT IS THE DESIGN.
     *
     * Restoring a plain unique index means one value may exist once across the whole table. If
     * any rejoiner has been registered, an IC is held by both a superseded record and a live
     * one, and the only ways back are emptying the old value or deleting the old row —
     * **both of which `adr/0015` exists to refuse.** A rollback that quietly did either would
     * destroy the identity on the historical row that `previous_employee_id` points at.
     *
     * So this throws instead, naming the values it found. Proved rather than assumed: running
     * the naive version with duplicates present returns
     * `SQLSTATE[23000]: 1062 Duplicate entry '900101145501'` from inside the migration, which
     * is a stack trace rather than an explanation.
     */
    public function down(): void
    {
        // ⚠ PRE-FLIGHT, BEFORE ANY DDL. MySQL DDL auto-commits, so a check made after the first
        // DROP cannot undo it — by then the table has already lost an index it may not be able
        // to get back.
        foreach (self::SCOPED_COLUMNS as $column) {
            $clashes = DB::table('employees')
                ->select($column)
                ->whereNotNull($column)
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1')
                ->pluck($column);

            if ($clashes->isNotEmpty()) {
                throw new RuntimeException(
                    "Cannot roll back adr/0015: employees.{$column} holds {$clashes->count()} "
                    .'value(s) shared by more than one record — '.$clashes->implode(', ').'. '
                    .'That is a registered rejoiner, which is what this migration exists to '
                    .'allow. Restoring the plain unique index requires emptying one of the '
                    .'values or deleting one of the rows, and adr/0015 refuses both: the '
                    .'historical row is the one previous_employee_id points at. Decide what '
                    .'should happen to that data before rolling back.'
                );
            }
        }

        // ⚠ CREATE FIRST, DROP SECOND — the reverse of up(), deliberately. If a CREATE fails
        // here despite the check above, nothing has been dropped yet and the table is still
        // fully protected. The obvious order leaves it with NO unique index at all in exactly
        // that case, which is worse than either the old state or the new one. The two names
        // differ, so both indexes coexist for the moment between the statements.
        foreach (self::SCOPED_COLUMNS as $column) {
            DB::statement("CREATE UNIQUE INDEX employees_{$column}_unique ON employees ({$column})");
            DB::statement("DROP INDEX employees_{$column}_live_unique ON employees");
        }

        // ⚠ THE COLUMN LAST. MySQL refuses to drop a column a functional index still
        // references, so this only succeeds once every index above has gone.
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('superseded_at');
        });
    }
};
