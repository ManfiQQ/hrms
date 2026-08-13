<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What work a person does, as distinct from what they may approve (adr/0003 decision 2).
 *
 * A REFERENCE TABLE, NOT AN ENUM, because the list is not stable: it grows as the factory,
 * studio, galleria and restaurant are mapped. Starting set: BDO, Admin, Media, Live Host,
 * Operation Crew.
 *
 * ⚠ NO `company_id`, AND THAT IS THE DESIGN — the vocabulary is GROUP-WIDE and Master Admin
 * owns it. A per-company list would be six lists, and six lists is how "Media", "media" and
 * "Media Team" end up meaning one thing (CLAUDE.md §5). Where the person performs the
 * function is `employee_job_functions.company_id`, which is a different question.
 *
 * The scope guard test skips this model by there being no `company_id` column at all, the
 * same footing as `sequences` — not by an opt-out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_functions', function (Blueprint $table) {
            $table->id();

            // ⚠ UNIQUE. schema.md's stated defence against the vocabulary drifting into three
            // spellings is that one account owns it; this makes the same rule structural
            // rather than procedural, which CLAUDE.md §2 prefers wherever it is available.
            //
            // It also enforces the reactivate-don't-recreate rule below: a soft-deleted
            // "Media" keeps its name reserved, so the only way back is to restore the original
            // row, carrying its history with it.
            $table->string('name')->unique();

            // Nullable — "Admin" and "Media" explain themselves, and requiring prose here
            // produces filler rather than description.
            $table->string('description')->nullable();

            // ⚠ THERE IS NO `is_active`, AND NONE MAY BE ADDED (decided 2026-08-13).
            //
            // schema.md listed one beside soft deletes while its own prose said a "deleted"
            // function IS a deactivated one — two columns for one state, which is the pattern
            // already rejected by name for is_enabled on employee_roles, secondary_company_id,
            // primary_role, hr_scope, is_master_admin and locked_permanently. The failure is
            // always the same shape: a row with deleted_at NULL and is_active false, and a
            // picker that filters on only one of them.
            //
            // Deactivation is the soft delete. deleted_at NULL means it appears in the
            // assignment picker; set means it does not, while existing employee_job_functions
            // rows stay intact and readable, and restoring brings it back.
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            $table->timestamps();

            // ⚠ Removal is soft delete ONLY. Hard-deleting a function employees currently hold
            // would orphan their rows and break history — adr/0008 decision 4 requires this
            // column in the migration that creates the table, because a table being created
            // now takes its full shape from its first line at zero cost.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_functions');
    }
};
