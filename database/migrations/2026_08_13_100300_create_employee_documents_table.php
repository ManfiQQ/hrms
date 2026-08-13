<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESCRIPTIVE child table — cascades on a company transfer (adr/0003 decision 7).
 *
 * Someone's IC scan is a fact about them, not about who pays them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('employee_id')->constrained('employees');

            // A fixed enum rather than free text (conventions.md §4). A STARTING SET, not
            // exhaustive — amendable by a future migration when HR needs more types.
            //
            // OTHER is a deliberate escape hatch so an unanticipated document is never blocked
            // from upload while that migration is written. It is also the one type the
            // EMPLOYEE MAY NOT SEE (employee-master.spec.md §6.3), which gives it a defined
            // purpose — internal notes and investigation material — rather than leaving it an
            // undifferentiated bucket.
            $table->enum('type', [
                'IC',
                'PASSPORT',
                'EDUCATION_CERTIFICATE',
                'OFFER_LETTER',
                'CONFIRMATION_LETTER',
                'RESIGNATION_LETTER',
                'OTHER',
            ]);

            // ⚠ WRITE ONCE. REPLACING A DOCUMENT IS A NEW ROW PLUS A SOFT DELETE OF THE OLD
            // ONE, NEVER AN EDIT TO THIS COLUMN (schema.md § employee_documents).
            //
            // The prohibition is what keeps `created_by` true. Overwrite the path in place and
            // `created_by` names whoever uploaded a file that is no longer there, while
            // `updated_by` names the person who actually supplied the current one — so the row
            // reads as though the first person uploaded the second person's file. Two rows
            // keep both facts true and preserve the version history.
            //
            // Enforced on the EmployeeDocument model, not left as a comment: the absence of an
            // edit path is not a guarantee, and an ->update() written anywhere would otherwise
            // succeed silently.
            $table->string('file_path');

            // ⚠ THERE IS NO `uploaded_by`, AND NONE MAY BE ADDED (schema.md, 2026-08-13).
            // The row is created by the upload, so `created_by` IS the uploader. Two columns
            // naming one actor is the duplication conventions.md §3 rejects on
            // `audit_logs.created_by` — "user_id IS the actor, so created_by would record the
            // same person twice" — and the copy is what goes stale.
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            $table->timestamps();

            // Soft deletes carry a second job on this table: they are half of the replacement
            // mechanism above, not merely an archive.
            $table->softDeletes();

            $table->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
