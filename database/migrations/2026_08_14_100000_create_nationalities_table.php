<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an employee holds citizenship — a reference table, not an enum (adr/0013 decision 6).
 *
 * A REFERENCE TABLE BECAUSE THE LIST GROWS. `conventions.md` §4 prefers a fixed enum where the
 * set is stable; nationality is not. This group hires from somewhere new and an enum would mean
 * a migration each time. `gender` lands on `employees` as an enum in the same ADR for the
 * opposite reason — that list does not grow. Two fields, two forms, one rule applied twice.
 *
 * ⚠ NO `company_id`, AND THAT IS THE DESIGN — one vocabulary for the whole group, the same
 * reasoning as `job_functions` (adr/0003 decision 2). Six per-company lists is how one thing
 * acquires three spellings (CLAUDE.md §5). The scope guard test skips this table by there being
 * no column to scope, the same footing as `job_functions` and `sequences` — not by an opt-out.
 *
 * ⚠ HR MAY CREATE ENTRIES HERE, AND THAT DIFFERS FROM `job_functions` ON PURPOSE (adr/0013
 * decision 6). HR meets a new nationality while registering an employee, and a hiring that
 * stalls until Master Admin acts is a rule that gets worked around. The cost is stated in the
 * ADR and is real: the unique index below stops `Bangladesh` twice, and it does not stop
 * `Myanmar` and `Burma` coexisting. The picker's autocomplete reduces that chance; it does not
 * remove it, and nothing in this file claims otherwise.
 *
 * BORN COMPLETE — `deleted_at`, `created_by` and `updated_by` are here in the migration that
 * creates the table (adr/0008 decision 4), and the authorship pair is NOT NULL from the first
 * line rather than corrected later as `job_functions` was (adr/0009 decision 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nationalities', function (Blueprint $table) {
            $table->id();

            // ⚠ UNIQUE. It is the only structural defence this vocabulary has, and it is
            // weaker here than on `job_functions` because more accounts may write to it —
            // see the HR note above. It also enforces reactivate-don't-recreate: a
            // soft-deleted `Nepal` keeps its name reserved, so the way back is restoring the
            // original row and the employees pointing at it.
            $table->string('name')->unique();

            // ⚠ NOT NULL from creation, unlike `job_functions`, which was created nullable and
            // corrected by 2026_08_13_100600. A null would mean either "written before the
            // observer existed" or "the observer failed", and nothing could tell the two apart
            // (adr/0009 decision 3). No row on this table can predate the observer.
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');

            $table->timestamps();

            // ⚠ Removal is soft delete ONLY, and deactivation IS the soft delete — there is no
            // `is_active` column and none may be added, the pattern rejected by name for
            // `is_enabled`, `secondary_company_id`, `primary_role`, `hr_scope` and
            // `uploaded_by`. Hard-deleting a nationality employees currently hold would break
            // the FK from `employees.nationality_id`, which is NOT NULL.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nationalities');
    }
};
