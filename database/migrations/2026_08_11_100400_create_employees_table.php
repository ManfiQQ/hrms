<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // Group-wide unique, format AHS-0001. The prefix is ALWAYS AHS — the parent
            // company — regardless of which subsidiary employs the person: an AIM employee
            // is still AHS-0042. Counterintuitive enough to be "corrected" by mistake, so:
            // it is intentional. The unique index is group-wide, NOT composite with
            // company_id (adr/0003 decision 9, employee-master.spec.md §10 decision 1).
            $table->string('employee_no')->unique();

            // Links a rejoiner's new record to their old one. RESIGNED and TERMINATED are
            // terminal (business-rules.md BR-2), so a returning employee gets a new record
            // with a new employee_no — never a reactivated one — and this is the only
            // thread back (adr/0003 decision 9).
            $table->foreignId('previous_employee_id')->nullable()->constrained('employees');

            // NOT NULL. Every other record in the system identifies a person by this;
            // an employee master where the name is optional cannot do its one job.
            $table->string('full_name');

            $table->string('nickname')->nullable();

            // Nullable and frequently absent — much of this workforce (factory crew, studio
            // staff, live hosts) has no company email. That is precisely why login runs on
            // phone_no (adr/0004 decision 6).
            $table->string('email')->nullable();

            // NOT NULL and UNIQUE: this is the login username (adr/0004 decision 6,
            // auth-rbac.spec.md BR-A1). Two employees sharing a number would make login
            // ambiguous. There is no placeholder path — a dummy number occupies the unique
            // index and hands one employee's username to another. HR therefore cannot
            // register an employee without a phone number, which is the accepted cost.
            //
            // The value is normalised before storing and before comparing (strip spaces,
            // dashes, leading +60 or 60) and validated at 9-12 digits; that belongs to the
            // Auth layer, not to the schema.
            $table->string('phone_no')->unique();

            // NOT NULL. The payroll and legal employer — that meaning only. It does not
            // answer "what authority does this person have"; employee_roles does
            // (adr/0003 decision 6). It BOUNDS read scope via the employer's position in
            // companies.parent_company_id, but never GRANTS visibility — roles do
            // (adr/0004 decision 1).
            //
            // No secondary_company_id column exists and none may be added: involvement with
            // other companies is derived from employee_roles, never stored twice.
            $table->foreignId('company_id')->constrained('companies');

            // Org assignment. Independent of company_id and NOT required to match it — an
            // employee may sit in a shared branch/department belonging to no single company,
            // or to a different one. That is a correct record and validation must not
            // reject it (adr/0002 decision 2).
            //
            // branch_id and position_id are NULLABLE: not every employee has a fixed place
            // of work or a titled position, and the legacy import carries records that have
            // neither. department_id stays NOT NULL because approval routing resolves per
            // (department, company) — an employee with no department has no HOD stage to
            // resolve (adr/0001 decision 3, adr/0002 decision 4).
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->foreignId('department_id')->constrained('departments');
            $table->foreignId('position_id')->nullable()->constrained('positions');

            // Matches the NGTime attendance export ID. Current value only — a re-enrolment
            // overwrites in place, and Phase 1 keeps no enrolment history.
            $table->string('fingerprint_id')->nullable()->unique();

            // DISPLAY ONLY — org chart, directory grouping, seniority tier. It never drives
            // an authorization or routing decision (adr/0001 decision 1). ADMIN is
            // deliberately excluded: it conflated a system permission with an org tier.
            $table->enum('level', ['STAFF', 'SUPERVISOR', 'MANAGER', 'HOD']);

            $table->enum('employment_type', [
                'FULL-TIME', 'PART-TIME', 'CONTRACT', 'INTERN', 'FREELANCE',
            ]);

            // Setting RESIGNED or TERMINATED freezes the user account and revokes every
            // employee_roles row in the same transaction (adr/0004 decision 5). RESIGNED
            // and TERMINATED are terminal — there is no reactivation, by anyone.
            $table->enum('staff_status', [
                'PROBATION', 'ACTIVE', 'CONFIRMED', 'SUSPENDED', 'RESIGNED', 'TERMINATED',
            ]);

            $table->date('join_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->date('confirmation_date')->nullable();

            // Two-tier reporting, confirmed from the legacy Staff Master template.
            $table->foreignId('direct_supervisor_id')->nullable()->constrained('employees');
            $table->foreignId('manager_id')->nullable()->constrained('employees');

            // FIXED = late after the configured start time. FLEXIBLE = OT applied manually.
            $table->enum('attendance_type', ['FIXED', 'FLEXIBLE']);

            // Structured columns, not free text. The legacy system stored these as strings
            // ("9.00 AM - 5.00 PM"), which cannot be calculated against (conventions.md §4).
            $table->time('work_start_time');
            $table->time('work_end_time');

            // NULLABLE, and null means NOT APPLICABLE — not "unknown" and not "zero".
            //
            // For attendance_type = FLEXIBLE, overtime is applied manually and there is no
            // threshold at all. Forcing a value would put a real-looking time in the column
            // that future code reads as a genuine OT threshold, silently computing overtime
            // for employees whose OT is decided by a human. A wrong number is worse than an
            // absent one, because only the absent one can be detected.
            //
            // Required when attendance_type = FIXED. That rule is enforced in the SERVICE
            // LAYER, not as a database constraint: it is conditional on another column, and
            // conventions.md §1 puts business logic in services rather than in the schema.
            $table->time('ot_after_time')->nullable();

            // JSON arrays, e.g. ["MON","TUE","WED","THU","FRI","SAT"]. The legacy system
            // stored "ISNIN - SABTU" as a string — unquery-able (conventions.md §4).
            $table->json('working_days');
            $table->json('offday');

            // Whether Saturday accumulated-hours banking applies to this employee.
            $table->boolean('hours_enabled')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            $table->timestamps();
            $table->softDeletes();
        });

        // The users.employee_id foreign key is added HERE, in the migration that creates
        // the table it points at — not in a separate migration.
        //
        // The column itself has existed since users was created, which is what Principle #4
        // requires; that rule is about the column, not the constraint. The constraint could
        // not exist then because employees did not. Adding it at the first moment it CAN
        // exist is ordering, not the later "repair migration" pattern conventions.md §7
        // warns against — a standalone add_foreign_key_to_users migration would be that
        // pattern, and is deliberately not what happens here (schema.md § users.employee_id).
        //
        // The column stays nullable permanently regardless: Master Admin and Director
        // accounts have no employee record and carry null here for good (adr/0001
        // decision 4, adr/0004 decision 4).
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees');
        });
    }

    public function down(): void
    {
        // Drop the constraint before the table it references.
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::dropIfExists('employees');
    }
};
