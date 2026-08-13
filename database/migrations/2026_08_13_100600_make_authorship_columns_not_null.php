<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `created_by` and `updated_by` become NOT NULL — `adr/0009` decision 3.
 *
 * ⚠ A FORWARD MIGRATION, NOT AN EDIT TO AN EXISTING ONE. `conventions.md` §11 does not apply
 * to this file: the eight creating migrations are untouched, and the columns genuinely change
 * shape rather than being corrected in place. §11 applies to the OTHER half of this change —
 * dropping the development database so no row predates the observer — and that use is logged
 * there as the second.
 *
 * ⚠ THE COLUMNS ARE NOT NULLABLE BECAUSE NULL WOULD MEAN TWO THINGS. Left nullable, a null
 * would read as either *written before the observer existed* or *the observer failed*, and
 * nothing could tell the two apart. `adr/0009` decision 3 refuses that ambiguity, which is
 * only affordable while no real data exists.
 *
 * ⚠ THIS IS WHAT MAKES THE `uploaded_by` REMOVAL TRUE. `schema.md` dropped
 * `employee_documents.uploaded_by` on the argument that `created_by` already records the
 * uploader — a sentence that was false for every row written before AuthorshipObserver. The
 * observer makes it true going forward; this migration makes it impossible to un-true.
 *
 * ⚠ NOT LISTED HERE, and deliberately: `branches`, `departments` and `positions` carry no
 * authorship columns at all yet (`adr/0008` decision 3 defers them to Org Structure), and
 * `audit_logs` / `security_events` carry none by design — `user_id` IS the actor there, so
 * `created_by` would record the same person twice (`conventions.md` §3).
 */
return new class extends Migration
{
    /**
     * Every table carrying the pair, verified against information_schema before writing this.
     *
     * @var list<string>
     */
    private const TABLES = [
        'employees',
        'employee_roles',
        'employee_family_members',
        'employee_education_history',
        'employee_employment_history',
        'employee_documents',
        'job_functions',
        'employee_job_functions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                // The foreign keys to `users` are constraints, not part of the column
                // definition, so they survive the MODIFY unchanged.
                $blueprint->unsignedBigInteger('created_by')->nullable(false)->change();
                $blueprint->unsignedBigInteger('updated_by')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('created_by')->nullable()->change();
                $blueprint->unsignedBigInteger('updated_by')->nullable()->change();
            });
        }
    }
};
