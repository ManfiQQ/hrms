<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only employment ledger — adr/0003 decision 8, schema.md.
 *
 * Every status, grade, position and department change is a NEW ROW, never an overwrite of
 * the current record. That is what answers "when did this employee move from Grade C to D",
 * which the legacy system's flat-field design could not do at all: it held only the current
 * value, so every previous one was simply gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_status_history', function (Blueprint $table) {
            $table->id();

            // ⚠ AN EVENT TABLE'S company_id IS A FROZEN HISTORICAL FACT — the employer at
            // the moment the change happened, written once and NEVER cascaded when the
            // employee later transfers to another group company (adr/0003 decision 7,
            // conventions.md §2). A promotion recorded under AIM must not become TURSENIA's
            // because the person moved afterwards; that is not an update, it is
            // falsification.
            //
            // ⚠ CONSEQUENCE, and the reason the model releases its scope through the
            // employee relationship: after a transfer these rows carry the OLD company_id,
            // so the ordinary tenant scope filters them out and the employee's history tab
            // appears to BEGIN ON THE TRANSFER DATE. Fewer rows, no error, nothing to
            // notice. See the EmployeeStatusHistory model and Employee::statusHistory().
            $table->foreignId('company_id')->constrained('companies');

            $table->foreignId('employee_id')->constrained('employees');

            // FOUR values, and only four.
            //
            // ⚠ CORE_ROLE IS DELIBERATELY ABSENT AND MUST NOT BE ADDED. Role history lives
            // in employee_roles, which already records every grant and revocation with its
            // date, actor and reason. Writing the same event here too would create two
            // records of one fact that can disagree — and the copy is the one that goes
            // stale (adr/0003 decision 8). A service that appends a row here for a role
            // change is wrong; the pivot row is the whole record.
            //
            // The UI merges this table and employee_roles into one chronological timeline,
            // so HR reads a single history without the data being stored twice. That merge
            // is a READ-side concern and must not tempt a writer into recording the event
            // in both places to make a query simpler.
            $table->enum('change_type', [
                'STAFF_STATUS',
                'POSITION',
                'DEPARTMENT',
                'LEVEL',
            ]);

            // Nullable: the first row for a field has no previous value.
            $table->string('old_value')->nullable();
            $table->string('new_value');

            // ⚠ A SNAPSHOT OF THE DISPLAY TEXT AT THE TIME. Storing only department_id = 7
            // would need a join to render, and that join shows the department's name TODAY,
            // not its name THEN — so renaming a department would retroactively rewrite
            // history, and a ledger that changes retroactively is not a ledger.
            //
            // Redundant for enum types (CONFIRMED / CONFIRMED). Accepted: one uniform row
            // shape costs a few bytes and avoids per-type branching in every reader.
            // To be reviewed once the system runs on real data (schema.md).
            $table->string('old_label')->nullable();
            $table->string('new_label');

            // ⚠ Distinct from created_at, and the distinction is the point. effective_date
            // is when the change APPLIES — a promotion is typically effective before HR gets
            // to enter it — while created_at is when it was typed. BR-A17's ten-day account
            // expiry counts from this column, not from the row's timestamp.
            $table->date('effective_date');

            $table->text('reason')->nullable();

            $table->foreignId('changed_by')->nullable()->constrained('users');

            // ⚠ APPEND-ONLY: created_at alone. No updated_at, no updated_by, no soft
            // deletes, and a migration author must not add them back for consistency's sake
            // — this is a documented exception to conventions.md §3. A correction is a NEW
            // ROW. Mutability would defeat the entire point: a ledger that can be rewritten
            // after the fact cannot answer "when did this employee move from Grade C to D"
            // with any authority.
            $table->timestamp('created_at')->useCurrent();

            // The employee's own history tab, in order — the table's primary read.
            $table->index(['employee_id', 'effective_date']);

            // "How many promotions did TURSENIA make this year" — the direct reporting read,
            // which stays tenant-scoped in full.
            $table->index(['company_id', 'effective_date']);

            // "Every status change this quarter", without scanning the employee index.
            $table->index(['change_type', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_status_history');
    }
};
