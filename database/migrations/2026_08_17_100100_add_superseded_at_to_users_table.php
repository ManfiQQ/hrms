<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `superseded_at` on `users`, and the unique index on `phone_no` rebuilt over an expression —
 * `adr/0015` decisions 2 and 3.
 *
 * ⚠ THIS IS THE HALF THAT FAILED WORSE, AND IT IS THE SAME QUESTION AS `employees.ic_no`.
 * `schema.md` and `auth-rbac.spec.md` BR-A18 both recorded that fixing either alone leaves the
 * rejoining flow just as blocked, which is why `adr/0015` covers all four columns and why this
 * migration is paired with `2026_08_17_100000`.
 *
 * A rejoiner brings the same phone number, because it is theirs. `phone_no` is NOT NULL and
 * unique because it is the login username (`adr/0006`), `User` has **no soft deletes**, and a
 * terminal status freezes and expires the account (BR-A15, BR-A17) without ever removing the
 * row. So the old row went on holding that number for ever, and `CreateEmployee` failed on
 * `users_phone_no_unique` **inside its own transaction as a raw 1062** — a 500 rather than a
 * message naming a field.
 *
 * ⚠ EVERY ESCAPE WAS ALREADY CLOSED BY A RULE THAT IS CORRECT ON ITS OWN: no second number
 * (`adr/0006` decision 7), no placeholder (BR-A1), no reactivation (BR-A18), no employee without
 * an account (BR-A20). Deleting the old row would destroy the audit trail the freeze exists to
 * preserve. **Nothing here is emptied and nothing is deleted** — the frozen account keeps its
 * number and releases only its claim on it.
 *
 * ⚠ MySQL DDL IS NOT TRANSACTIONAL. See the same note on the `employees` migration; `down()`
 * here follows the same check-first, create-before-drop shape and for the same reasons.
 *
 * ⚠ `email` AND `activation_token` ARE NOT REBUILT. Both are unique on this table and neither
 * is an identity a rejoiner brings back: `email` is nullable and authenticates nothing
 * (`adr/0006`), and an `activation_token` dies the moment it is redeemed (BR-A21). Scoping
 * either would widen this migration past what `adr/0015` decided.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⚠ THE COLUMN FIRST — the index expression below references it.
        Schema::table('users', function (Blueprint $table) {
            // ⚠ THIS TABLE HAS NO SOFT DELETES AND IS NOT GAINING ANY. `superseded_at` is not a
            // substitute for one: the account row stays, stays queryable, and stays attached to
            // its audit trail. It has released a username, not been removed
            // (`adr/0015` decision 2).
            //
            // ⚠ NOT DERIVED FROM EXPIRY, THOUGH IT WILL USUALLY COINCIDE WITH IT. A superseded
            // account was already frozen and expired, but `AccountExpiry` computes that from the
            // latest terminal ledger row plus a configured window — and an index cannot compute.
            // Two rows in the same state for different reasons is exactly the duplication this
            // project refuses; the difference is that one is a fact and the other is a function.
            $table->timestamp('superseded_at')->nullable()->after('employee_id');
        });

        // ⚠ Name verified against information_schema, not assumed.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_phone_no_unique');
        });

        // ⚠ RAW SQL — Blueprint::unique() quotes its argument as a column identifier and cannot
        // express this. ⚠ THE DOUBLE PARENTHESES ARE REQUIRED: `(expr)` is a column list,
        // `((expr))` is a functional key part.
        //
        // ⚠ WHAT THIS DOES NOT LOOSEN: two LIVE accounts still cannot share a number. Verified
        // on MySQL 8.4.11 — superseded + live sharing a value is allowed, two live rows sharing
        // one is refused. That distinction is the whole of this migration; if it ever stops
        // holding, one person can log in as another.
        DB::statement(
            'CREATE UNIQUE INDEX users_phone_no_live_unique '
            .'ON users ((IF(superseded_at IS NULL, phone_no, NULL)))'
        );
    }

    /**
     * ⚠ CAN LEGITIMATELY REFUSE TO RUN — see the `employees` migration's `down()` for the full
     * reasoning. Here the stake is higher: restoring the plain index requires that one phone
     * number exist once across the whole table, and the only ways to get there are emptying a
     * NOT NULL column — which is impossible — or deleting an account row, which destroys the
     * audit trail BR-A15 and BR-A17 exist to keep.
     *
     * So for this table a clash is not merely awkward, it is **unresolvable without data loss**,
     * and the exception says so rather than letting a 1062 imply it.
     */
    public function down(): void
    {
        // ⚠ PRE-FLIGHT, BEFORE ANY DDL, because DDL auto-commits and a check made afterwards
        // cannot undo the DROP that preceded it.
        $clashes = DB::table('users')
            ->select('phone_no')
            ->groupBy('phone_no')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('phone_no');

        if ($clashes->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot roll back adr/0015: users.phone_no is shared by more than one account '
                ."for {$clashes->count()} number(s) — ".$clashes->implode(', ').'. That is a '
                .'registered rejoiner holding their own number twice, which is what this '
                .'migration exists to allow. The column is NOT NULL so it cannot be emptied, '
                .'and deleting the older account destroys the audit trail its freeze and expiry '
                .'exist to preserve (BR-A15, BR-A17). There is no rollback that does not lose '
                .'data — decide what should happen to it first.'
            );
        }

        // ⚠ CREATE FIRST, DROP SECOND — the reverse of up(). If the CREATE fails despite the
        // check, nothing has been dropped and every account is still protected by the
        // functional index. The obvious order would leave the login username unconstrained,
        // which is the one state this table must never be in.
        DB::statement('CREATE UNIQUE INDEX users_phone_no_unique ON users (phone_no)');
        DB::statement('DROP INDEX users_phone_no_live_unique ON users');

        // ⚠ THE COLUMN LAST — MySQL refuses to drop one a functional index still references.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('superseded_at');
        });
    }
};
