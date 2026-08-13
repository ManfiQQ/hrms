<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESCRIPTIVE child table — schema.md § Company transfer, conventions.md §2.
 *
 * The three-question test: if this person's payroll employer changed tomorrow, would this row
 * still be true? Yes, and it is about the PERSON — their spouse is their spouse whoever pays
 * them. So `company_id` here is a TENANT MARKER and it CASCADES on a company transfer
 * (adr/0003 decision 7). Getting the category wrong corrupts data that looks fine at insert
 * time and only breaks after a transfer months later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_family_members', function (Blueprint $table) {
            $table->id();

            // Denormalized from the parent employee so the tenant global scope applies
            // uniformly, and so a compromised or mistaken employee_id cannot leak rows across
            // tenants (schema.md). Present from creation, per CLAUDE.md Principle #4.
            $table->foreignId('company_id')->constrained('companies');

            $table->foreignId('employee_id')->constrained('employees');

            // Free text, not an enum. The relationships this workforce records are open-ended
            // (spouse, child, father, mother-in-law, guardian) and a fixed list would reject a
            // real family rather than describe it. conventions.md §4 governs values the SYSTEM
            // CALCULATES AGAINST — working hours, rates, day lists. Nothing computes on this.
            $table->string('relationship');

            $table->string('name');

            // ⚠ NULLABLE, and the reason is the same one that rejected a placeholder phone
            // number for an employee (auth-rbac.spec.md BR-A1) and a placeholder email
            // (schema.md § users.email). A child has no phone; a parent may have none either.
            // NOT NULL here would not produce a phone number, it would produce an invented
            // one — and an invented number in a contact field is worse than an empty one,
            // because only the empty one is visibly absent.
            $table->string('contact_no')->nullable();

            // employee-master.spec.md §6.2's deliberate exception: name and number only are
            // surfaced on the EMPLOYMENT tab, where a supervisor can reach them, rather than
            // behind the Family tab they may not read. If there is an accident at work the
            // supervisor is likely the first person present.
            $table->boolean('is_emergency_contact')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            // employee-master.spec.md §3 — the child-table read is always "this employee's
            // family, within my scope", so the two columns are used together and in that
            // order.
            $table->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_family_members');
    }
};
