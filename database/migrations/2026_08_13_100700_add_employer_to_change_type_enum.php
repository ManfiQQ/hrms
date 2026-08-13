<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `change_type` gains a fifth value, `EMPLOYER` — `adr/0010` decision 1.
 *
 * ⚠ A FORWARD MIGRATION. `conventions.md` §11 does not apply: the creating migration
 * (`2026_08_12_100300`) is untouched, and this genuinely changes the column rather than
 * correcting it in place.
 *
 * ⚠ WHY THE COMPANY TRANSFER EARNS A LEDGER VALUE AT ALL. `employees.company_id` was the only
 * mutable column on `employees` with no history, and the most statutorily loaded of them: it
 * decides which legal entity owes an employee's EPF, SOCSO and EA Form.
 * `employee-master.spec.md` §5.7 requires the record to show which entity was responsible
 * **from which date**, and nothing answered that except reconstructing it from audit rows.
 *
 * ⚠ NAMED FOR THE FIELD, AS THE OTHER FOUR ARE. `employees.company_id` means "the payroll and
 * legal employer — that meaning only" (`schema.md`). `COMPANY` was rejected because a row
 * reading `change_type = COMPANY` beside its own `company_id` column uses one word for two
 * different things on one row.
 *
 * ⚠ `CORE_ROLE` IS STILL ABSENT AND STILL MUST BE. Role history lives in `employee_roles`,
 * which records every grant and revocation with dates, actors and reasons; writing the same
 * event here too would create two records of one fact (`adr/0003` decision 8). A fifth value
 * is not an invitation for a sixth.
 */
return new class extends Migration
{
    /** The five, in the order the column has always listed them plus the new one last. */
    private const VALUES = [
        'STAFF_STATUS',
        'POSITION',
        'DEPARTMENT',
        'LEVEL',
        'EMPLOYER',
    ];

    private const PREVIOUS = [
        'STAFF_STATUS',
        'POSITION',
        'DEPARTMENT',
        'LEVEL',
    ];

    public function up(): void
    {
        Schema::table('employee_status_history', function (Blueprint $table) {
            $table->enum('change_type', self::VALUES)->change();
        });
    }

    public function down(): void
    {
        // ⚠ Will fail while any EMPLOYER row exists, and that is correct rather than
        // inconvenient: narrowing the enum under live rows would either truncate them to an
        // empty string or drop the only record of which entity employed somebody from when.
        Schema::table('employee_status_history', function (Blueprint $table) {
            $table->enum('change_type', self::PREVIOUS)->change();
        });
    }
};
