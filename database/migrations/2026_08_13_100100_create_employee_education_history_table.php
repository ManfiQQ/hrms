<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESCRIPTIVE child table — cascades on a company transfer (adr/0003 decision 7).
 *
 * Where someone studied is a fact about the person, not about who employs them, so the row
 * stays true after a transfer and its tenant marker moves with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_education_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('employee_id')->constrained('employees');

            // ⚠ ACADEMIC level — SPM, STPM, Diploma, Degree, Master. This is NOT the same
            // thing as `employees.level`, which is an org seniority tier (STAFF, SUPERVISOR,
            // MANAGER, HOD) and an enum. Two columns, one word, unrelated meanings.
            //
            // A string rather than an enum, deliberately: schema.md writes the set as
            // "SPM/Diploma/Degree/etc." — an open list. Foreign qualifications and
            // professional certificates arrive without a migration, and rejecting a real
            // certificate because the enum is short is a worse failure than an untidy value.
            $table->string('level');

            $table->string('institution');

            // A YEAR column rather than a string — conventions.md §4. The legacy system's
            // habit of storing dates as free text is what that rule exists to stop, and a
            // graduation year is compared and sorted.
            $table->year('year');

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_education_history');
    }
};
