<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESCRIPTIVE child table — cascades on a company transfer (adr/0003 decision 7).
 *
 * ⚠ THIS TABLE IS ABOUT EMPLOYMENT ELSEWHERE — jobs held BEFORE joining this group. It is not
 * a record of movement between group entities, which is `employee_status_history` (an EVENT
 * table, frozen forever) plus `employees.company_id` changing in place. Two tables that sound
 * alike and behave in opposite ways on a transfer: this one cascades, that one must not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_employment_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('employee_id')->constrained('employees');

            // ⚠ A STRING, and deliberately NOT a foreign key to `companies`. This is a former
            // employer outside the group — the whole point of the record. A FK would only be
            // able to express prior employment at one of the six group entities, which is the
            // one case this table is not for, and it would silently make every genuine
            // outside employer unrecordable.
            $table->string('company_name');

            // ⚠ Likewise a string, NOT a foreign key to `positions`. `positions` describes
            // roles inside this group's own org structure; another company's job title is
            // theirs, not ours, and importing it into our vocabulary would corrupt the org
            // chart with titles nobody here holds.
            $table->string('position');

            $table->date('start_date');

            // Nullable — a record can be entered while the person is still serving a notice
            // period at the previous employer, which is the ordinary case at hiring time.
            $table->date('end_date')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_employment_history');
    }
};
