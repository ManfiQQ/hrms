<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Twelve identity and statutory columns on `employees` — `adr/0013` decision 1.
 *
 * ⚠ A FORWARD MIGRATION, AND `conventions.md` §11 WAS AVAILABLE AND DELIBERATELY NOT USED.
 * All three of its conditions still hold today — no production environment, no real data, one
 * developer — so `2026_08_11_100400_create_employees_table.php` could have been edited in place
 * at zero debt, and it has not been touched.
 *
 * The reason is what §11 is FOR. It exists to correct what should already have been there: the
 * `(company_id, staff_status)` index was required by `employee-master.spec.md` §3 from the
 * start and simply never written, so dating it to the creating migration told the truth. These
 * twelve columns are the opposite case. **They are a NEW REQUIREMENT, found on 2026-08-14**,
 * when asking who may edit each tab exposed that the Personal tab had almost nothing behind it
 * (`adr/0013`, UI §7 gap 4). Nobody omitted them on 11 August; nobody had established they were
 * needed.
 *
 * Editing the creating migration would date `ic_no`, `date_of_birth` and the EPF and SOCSO
 * numbers to 11 August and erase the three days in which the gap was found and argued. The
 * history would then read as though Employee Master had always known it needed statutory
 * fields — which is precisely the discovery `adr/0013` exists to record. **There is no third
 * entry in the §11 usage log, and that absence is a choice rather than an oversight.**
 *
 * ⚠ THREE COLUMNS ARE NOT NULL WITH NO DEFAULT, WHICH ASSUMES THE TABLE IS EMPTY. Against a
 * populated `employees` this would fail outright or fill implicit defaults — a date of birth of
 * `0000-00-00` — depending on `sql_mode`, and the second is worse than the first. It does not
 * arise because no rows exist anywhere yet, the same window §11 rests on. **When the legacy
 * import runs, a row missing any of the three cannot be created at all** — recorded in
 * `CLAUDE.md` §10 question (f), and a data-entry problem rather than a schema one.
 *
 * ⚠ NO `personal_phone`, AND NONE MAY BE ADDED. `users.phone_no` is already the personal number
 * as well as the login username (`adr/0006`), and a second column would be two numbers for one
 * person: HR updates one, login reads the other, and an employee is locked out of their own
 * account with nothing to notice. The Personal tab displays it read-only through
 * `Employee::user()`.
 *
 * ⚠ NO SALARY DATA. `bank_name` and `bank_account_no` record where money is SENT, never how
 * much — Employee Master holds no salary at all (`employee-master.spec.md` §10 decision 3), and
 * reading salary is the `ACCOUNT` role's alone (`adr/0003` decision 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // ⚠ TWO COLUMNS, NOT ONE `identity_no` + `identity_type` PAIR (adr/0013 decision 2).
            // Every statutory integration asks for the number by its Malaysian name — EPF,
            // SOCSO and the EA Form all want *No. KP* — and a column that is sometimes a
            // passport makes each of those a conditional read forever. Storing a passport in
            // `ic_no` was rejected outright: the unique index below would then be silently
            // enforcing a rule about passports.
            //
            // ⚠ UNIQUENESS IS PER COLUMN, NOT ACROSS THE PAIR. Nothing here stops one person's
            // `ic_no` matching another's `passport_no`. That is not a real identity collision,
            // and the ADR states the cost so nobody reads these two indexes as covering more
            // than they do.
            //
            // ⚠ "At least one of the two is required" is a FormRequest rule and NOT a database
            // constraint — it is conditional on the other column, the same shape as
            // `ot_after_time`. It lands with the form; until then nothing enforces it, and
            // `adr/0013` records that deferral as a dated amendment.
            $table->string('ic_no')->nullable()->unique();
            $table->string('passport_no')->nullable()->unique();

            // Nullable, and an expired permit blocks NOTHING (adr/0013 decision 4). A
            // non-citizen may have no permit recorded — it may be in process, or they hold a
            // different pass — and requiring the date would produce a fabricated one. Renewal
            // is the response, not suspension: the record raises a flag, and once the
            // Notification Engine exists it notifies. ⚠ The flag covers only records that
            // CARRY a date; an empty one is never flagged, which is the direct cost of this
            // nullability.
            $table->date('permit_expiry')->nullable();

            // ⚠ NOT NULL. SOCSO's contribution rate changes at 60 and EIS eligibility turns on
            // age, so Payroll cannot compute a contribution without it. It is also the one
            // field here with a remediation path — it can be read off an IC scan or a
            // personnel file — which is why CLAUDE.md §10 (f) treats it as blocking the import
            // as designed while being fixable by data entry.
            $table->date('date_of_birth');

            // ⚠ AN ENUM, WHERE `nationality` IS A TABLE, AND THE DIFFERENCE IS THE RULE IN
            // conventions.md §4 APPLIED TWICE. Gender does not grow; nationality does, every
            // time the group hires from somewhere new, and an enum would mean a migration each
            // time. Required by the EA Form and by maternity entitlement.
            $table->enum('gender', ['MALE', 'FEMALE']);

            // ⚠ NOT NULL, pointing at the group-wide vocabulary created by
            // 2026_08_14_100000. Constrained, so a nationality employees hold cannot be hard
            // deleted — withdrawal is the soft delete.
            $table->foreignId('nationality_id')->constrained('nationalities');

            // ⚠ ONE TEXT COLUMN, NOT PARSED INTO STREET, CITY, POSTCODE AND STATE. It is
            // written onto forms and letters as a block and never queried by component.
            // Structuring it would create five columns nobody filters on and four more ways to
            // leave it half-complete (adr/0013 decision 1).
            $table->text('address')->nullable();

            // ⚠ NULLABLE, AND THAT IS A FACT RATHER THAN A COMPROMISE (adr/0013 decision 3).
            // Probationers, contract staff and interns hold no EPF or SOCSO number until they
            // qualify, so a record without one is CORRECT, not incomplete. NOT NULL would
            // produce an invented number for every intern — the same failure that banned a
            // placeholder phone number (BR-A1): a fabricated number fills the field without
            // filling the fact, and only an empty one is visibly empty.
            //
            // ⚠ A CONFIRMED employee with neither is FLAGGED, NEVER BLOCKED (decision 5).
            // Registration takes a month or two, and blocking payroll over paperwork means
            // somebody is not paid. Contributions still accrue meanwhile, and settling them
            // retroactively is Payroll's inherited requirement, not this module's.
            $table->string('epf_no')->nullable();
            $table->string('socso_no')->nullable();

            // LHDN, for PCB and the EA Form.
            $table->string('tax_no')->nullable();

            // Where salary is SENT. Not how much — see the class docblock.
            $table->string('bank_name')->nullable();
            $table->string('bank_account_no')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // The FK first; MySQL will not drop a column an active constraint references.
            $table->dropConstrainedForeignId('nationality_id');

            // Dropping a column drops the unique indexes on `ic_no` and `passport_no` with it.
            $table->dropColumn([
                'ic_no',
                'passport_no',
                'permit_expiry',
                'date_of_birth',
                'gender',
                'address',
                'epf_no',
                'socso_no',
                'tax_no',
                'bank_name',
                'bank_account_no',
            ]);
        });
    }
};
