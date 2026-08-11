<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_roles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained('employees');

            // A REAL COMPANY REFERENCE, not a tenant marker. It answers "in which company
            // does this role apply", not "which tenant owns this row". That distinction is
            // load-bearing: it is why these rows are NEVER cascaded on a company transfer
            // (adr/0003 decision 7). A Manager role at AIM is still a Manager role at AIM
            // after the person's payroll moves elsewhere; cascading would corrupt the data.
            // Do not apply the ordinary tenant global scope to this column unthinkingly.
            $table->foreignId('company_id')->constrained('companies');

            // Six values. STAFF is deliberately absent: an ordinary staff member is someone
            // with NO row here at all, and defining a value for the absence of authority
            // would be a second way to express one state (adr/0003 decision 1).
            //
            // MASTER_ADMIN and DIRECTOR are absent too. A Master Admin has no employee
            // record, so it can hold no row here — the rule stays structurally impossible
            // to violate rather than test-enforced. Director authority is off-system
            // (adr/0001 decisions 2 and 7).
            //
            // Adding a value here changes the approval chain and requires an ADR, not a
            // UI form.
            $table->enum('role', [
                'ASSISTANT_DIRECTOR', 'HR', 'ACCOUNT', 'HOD', 'MANAGER', 'SUPERVISOR',
            ]);

            // Distinct from created_at: a promotion is typically effective before HR gets
            // to enter it, so the ledger records both the date it applies from and the date
            // it was typed.
            $table->date('effective_date');

            $table->foreignId('assigned_by')->constrained('users');

            // NULL = currently held. Rows are NEVER deleted: revoking sets this date, and
            // re-granting later inserts a NEW row, preserving the full cycle (held Jan-Aug,
            // revoked Aug, re-granted November) which a boolean toggle cannot express.
            //
            // ⚠ EVERY authority query must filter WHERE revoked_date IS NULL. Omitting it
            // returns revoked authority as current — a silent security failure, not an
            // error. It belongs in a default model scope, not repeated at each call site.
            $table->date('revoked_date')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->text('revoke_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            // NO SOFT DELETES, and none may be added. A deleted_at column would be a second
            // mechanism meaning "revoked", and every authority check would then have to test
            // both — the check that tests only one is a silent security hole. Revocation is
            // the single mechanism. The same reasoning bans an is_enabled flag here
            // (adr/0003 decisions 1 and 3, conventions.md §3).
            $table->timestamps();

            // Every authority check is now a query rather than a field read, so eager
            // loading must be disciplined or the employee list will N+1.
            $table->index(['employee_id', 'company_id']);
            $table->index(['company_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_roles');
    }
};
