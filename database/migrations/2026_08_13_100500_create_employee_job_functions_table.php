<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COMPANY-REFERENCE table — schema.md § Company transfer, the third cascade category.
 *
 * ⚠ ITS `company_id` IS NOT A TENANT MARKER, and this is the distinction that decides what
 * happens on a transfer. It answers "in which company does this person perform this
 * function", not "which tenant owns this row" — so the row is left ENTIRELY UNTOUCHED when
 * `employees.company_id` changes (adr/0003 decision 7).
 *
 * The three-question test: if this person's payroll employer changed tomorrow, would this row
 * still be true? Yes, because `company_id` here is not about the employer at all. Cascading it
 * would not merely hide data, it would CORRUPT it outright — the same reason `employee_roles`
 * is left alone, where a Manager role at AIM stays a Manager role at AIM after the person's
 * payroll moves elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_job_functions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained('employees');

            // A real company reference — see the class comment above. The EmployeeJobFunction
            // model therefore declares TENANT_SCOPE_EXEMPT rather than a scope class: scoping
            // it to the reader's companies would filter these rows by who is looking, which is
            // a different question from the one the column answers (adr/0005 decision 6).
            $table->foreignId('company_id')->constrained('companies');

            $table->foreignId('job_function_id')->constrained('job_functions');

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            // "What does this person do at this company" — employee-master.spec.md §3. Note
            // the column order is (employee_id, company_id) here and (company_id, employee_id)
            // on the descriptive child tables: those are read scope-first, this one is read
            // person-first, and the spec specifies each separately.
            $table->index(['employee_id', 'company_id']);

            // "Who performs this function" — the reverse lookup, and it is not optional: it is
            // what must be run before a job_functions row can be safely deactivated, so that
            // deactivation can report who still holds it instead of silently orphaning them.
            $table->index('job_function_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_job_functions');
    }
};
