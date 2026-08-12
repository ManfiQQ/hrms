<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // ONE ROW PER CHANGED FIELD, grouped by this UUID — generated ONCE per
            // transaction and stamped on every row that transaction produces, across every
            // table it touches. A save changing three fields writes three rows sharing one
            // batch_id (audit-trail.spec.md BR-AT2).
            //
            // Deliberately NOT a foreign key to an audit_batches table: a grouping key can
            // be stamped as rows are written, where an FK would require the parent row to
            // exist before any child could be. A forgotten batch_id therefore produces a
            // scattered display, not lost data — every row still carries its actor,
            // subject, field and timestamp.
            //
            // The JSON columns this table was drafted with (old_values / new_values) are
            // WITHDRAWN and must not return. conventions.md §4 forbids unstructured storage
            // where the system must query against it, and "who changed this employee's
            // salary, and when" must be a WHERE clause, not a scan-and-parse. A blob also
            // cannot be indexed for the salary filter that runs on EVERY HR read.
            $table->uuid('batch_id');

            // NULLABLE, and NULL is a meaningful value meaning "a system-level event" —
            // an audited action whose subject belongs to no company, such as a Master Admin
            // changing another Master Admin's system_access, or a tenant-scope bypass
            // entered through MasterAdminContext (adr/0005 decision 5). NOT NULL could hold
            // neither without inventing an attribution that is not true.
            //
            // ⚠ THIS TABLE TAKES A THIRD SCOPE CLASS, App\Models\Scopes\SystemTenantScope:
            //
            //     company_id IN (:read_scope)
            //     OR (company_id IS NULL AND the account has system_access = FULL)
            //
            // Both existing classes are wrong here, in OPPOSITE directions. TenantScope
            // hides the NULL rows from everyone including Master Admin — whose own actions
            // they mostly are, so it would conceal precisely the rows that exist to hold
            // the most powerful account to account. SharedTenantScope shows them to
            // everyone, so a subsidiary-employed HR would read every group-level
            // administrative action.
            //
            // NULL does NOT mean here what it means on branches / departments. There it is
            // "available to all companies"; here it is "attributable to no company" — the
            // opposite. Never pick a scope class because a column happens to be nullable.
            //
            // adr/0005 decision 6's guard test must RECOGNISE that third class rather than
            // exempt this model from it. See its amendment note, conventions.md §2, and
            // audit-trail.spec.md §11.
            $table->foreignId('company_id')->nullable()->constrained('companies');

            // The actor. Nullable for console and system-initiated writes.
            //
            // There is deliberately NO created_by: user_id IS the actor, and created_by
            // would record the same person twice. conventions.md §3's created_by /
            // updated_by requirement is met by this column plus the table being
            // append-only, and that exception is recorded there.
            $table->foreignId('user_id')->nullable()->constrained('users');

            // What was done, e.g. employee.transfer, employee_role.grant,
            // master_admin.scope_bypass.
            $table->string('action');

            // POLYMORPHIC SUBJECT — not employee_id (audit-trail.spec.md BR-AT3). Forced by
            // the writers this table already has: a system_access change has a users row
            // for a subject, an attendance correction has an attendance_import_rows row,
            // and a salary adjustment has a salary-ledger row. None of the three has an
            // employee to point at, so an employee_id column would be null for all of them
            // and "everything that ever happened to this record" would be unanswerable for
            // exactly the records where it matters most.
            //
            // morphs() also creates the (auditable_type, auditable_id) index this table
            // needs — do not add it again below.
            $table->morphs('auditable');

            // The column that changed. One row per field is what makes this a WHERE rather
            // than a scan, and it is what the salary filter matches on.
            $table->string('field');

            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            // A SNAPSHOT OF THE DISPLAY TEXT AT THE TIME (BR-AT4) — the same pattern, and
            // the same reason, as employee_status_history (adr/0003 decision 8). Storing
            // only department_id = 7 needs a join to render, and that join shows the
            // department's name TODAY, not its name THEN. Renaming a department would
            // retroactively rewrite the audit trail, and a record that changes
            // retroactively is not a record.
            //
            // Redundant for enums and scalars (CONFIRMED / CONFIRMED, 3200.00 / 3200.00).
            // Accepted: one uniform row shape costs a few bytes and avoids per-type
            // branching in every reader.
            //
            // There is NO value_type column and none may be added — metadata that can be
            // filled in wrong without anyone noticing, nothing validates it, nothing breaks
            // when it drifts, and the only thing it buys is cosmetic formatting
            // (audit-trail.spec.md §10 decision 4).
            $table->text('old_label')->nullable();
            $table->text('new_label')->nullable();

            // Nullable, and not decoration: MasterAdminContext::run() already takes a
            // reason and refuses a bypass without one (adr/0005 decision 5), and the
            // correction pattern in schema.md § attendance_corrections is
            // old_value / new_value / reason / corrected_by. Nullable because an ordinary
            // field edit has no reason to give, and inventing one would make the column
            // meaningless.
            $table->text('reason')->nullable();

            // APPEND-ONLY: created_at alone. No updated_at, no updated_by, no soft deletes,
            // and a migration author must not add them back for consistency's sake. A
            // correction is a new row. There is no update path and no delete path anywhere,
            // not for Master Admin (BR-AT6) — this is a deliberate exception to
            // conventions.md §3, recorded there.
            //
            // This is what makes it safe to let HR READ the log at all: the value of an
            // audit trail comes from not being able to DELETE it, not from not being able
            // to SEE it. A soft delete here would be a delete path with a nicer name.
            //
            // useCurrent() so that even a raw insert lands with a timestamp; no
            // ON UPDATE clause, because nothing ever updates.
            $table->timestamp('created_at')->useCurrent();

            // Every reader of one row wants the other rows of its batch.
            $table->index('batch_id');

            // The salary filter (BR-AT10) runs on this pair, on EVERY HR read: rows whose
            // (auditable_type, field) is a declared salary field are filtered out entirely
            // for HR and ASSISTANT_DIRECTOR — not masked, not counted, absent. The audit
            // log is the easiest back door in the system to miss, being the one table that
            // writes every value down a second time (adr/0003 decision 5).
            $table->index(['auditable_type', 'field']);

            // The scoped report, in date order. Must serve IS NULL lookups as well as
            // equality, since NULL is a real value on this column.
            $table->index(['company_id', 'created_at']);

            // "Everything this person did."
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
