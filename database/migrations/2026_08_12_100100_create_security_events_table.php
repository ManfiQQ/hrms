<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();

            // WHO TRIED TO GET IN. This is NOT a variant of an audit_logs row, and the two
            // tables are never merged (audit-trail.spec.md BR-AT1): a failed login has no
            // old_value and never will. One table holding both would need a rule about
            // which columns are meaningful for which event type, and that rule would live
            // nowhere — not in the migration, not on the model, rediscovered differently by
            // each reader. Two tables state it in the only place that cannot be skipped.

            // NULLABLE, and this column is THE RETENTION DISCRIMINATOR (BR-AT11):
            //
            //   user_id NOT NULL — an attempt against an account that exists → kept FOREVER
            //   user_id NULL     — an attempt against a number in no account → 90 DAYS
            //
            // ⚠ Never set this defensively to a placeholder. Doing so silently converts a
            // 90-day row into a permanent one.
            //
            // The 90-day rule does not breach CLAUDE.md §3, which forbids deleting for
            // PERFORMANCE. Nothing here is deleted to make anything faster; the line is
            // between a record and noise. An attempt against a number that never existed
            // has no subject, so no statutory retention period, because there is nobody it
            // is about.
            $table->foreignId('user_id')->nullable()->constrained('users');

            // Read off auth-rbac.spec.md rather than defined here — this module records
            // what Auth emits, it does not decide what Auth emits. A new authentication
            // event is a change to what Auth does, so it arrives with a migration and an
            // amendment to that spec, not by a caller passing a new string.
            //
            // ⚠ An action performed ON an account by someone else is a DATA CHANGE and
            // belongs in audit_logs, not here: password reset and unlock by HR (BR-A7), QR
            // regeneration, a system_access change by Master Admin, and the session
            // deletion on TERMINATED (BR-A15). That last one must sit INSIDE the freeze
            // transaction and roll back with it, which audit_logs gives (BR-AT7) and this
            // table explicitly does not (BR-AT8).
            //
            // The dividing line is not "does it involve a login" but WHO THE EVENT IS
            // ABOUT: this table holds what the subject did or attempted, audit_logs holds
            // what was done to the account.
            $table->enum('event_type', [
                'LOGIN_SUCCESS',
                'LOGIN_FAILED',
                'ACCOUNT_LOCKED',
                'PASSWORD_CHANGED',
                'ACTIVATION_REDEEMED',
            ]);

            // The submitted login identifier — users.phone_no, NORMALISED per BR-A1
            // (strip spaces, dashes, leading +60 or 60) — stored whether or not it matches
            // an account. Normalising matters here: 012-345 6789 and +60123456789 must
            // group as repeated attempts against ONE number, not read as two.
            $table->string('identifier');

            // RECORDED, AND NEITHER IS EVIDENCE.
            //
            // They exist because BR-AT11 already decided to keep attempts against real
            // accounts forever, on the ground that an attack pattern is evidence — and a
            // pattern with no origin is barely a pattern. Forty-one failures over three
            // nights reads the same whether it is one person mistyping a new password or a
            // distributed attempt. Keeping the rows forever while storing nothing worth
            // keeping would pay the retention cost and receive none of the value, and these
            // columns CANNOT BE RETROFITTED: an ALTER adds the column, never the data for
            // events already past.
            //
            // ⚠ user_agent is an ATTACKER-CONTROLLED STRING — a hint, never proof. Anyone
            // can send any value, so a legitimate-looking agent proves nothing; only a
            // client declaring itself a script carries information. ip_address is weak in
            // the same way — trivially changed, shared behind NAT — which is exactly why
            // BR-A3 throttles on the ACCOUNT and not the IP.
            //
            // NEITHER COLUMN IS EVER AN INPUT to an authorization, throttling, or lockout
            // decision, and neither is rendered as confirmation of who someone was. Both
            // nullable: a console context has neither, and a placeholder would be a
            // fabricated fact. Stored verbatim and unparsed. See audit-trail.spec.md §11.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // ⚠ THIS TABLE CARRIES NO SCOPE CLASS AT ALL — not TenantScope, not
            // SharedTenantScope, and not the SystemTenantScope audit_logs uses — and
            // company_id cannot be NOT NULL.
            //
            // A security event happens BEFORE AUTHENTICATION. There is no authenticated
            // user from whom to resolve a read scope, and in the failed-attempt case there
            // may be no account at all: an attempt against a phone number that has never
            // existed here has no subject, so no employer, so no company. SystemTenantScope
            // does not fit either, because it reads the account's system_access and there
            // may be no account to read it from.
            //
            // company_id is filled where knowable — the event has a user_id, the user has
            // an employee, the employee has an employer — and left null where it is not. It
            // is a REPORTING CONVENIENCE, NEVER AN ACCESS CONTROL. Access control is a
            // read-time permission check (BR-AT9): Master Admin sees everything, HR and
            // ASSISTANT_DIRECTOR see within their read scope, and a null-user_id row —
            // belonging to no company — is Master Admin only.
            //
            // adr/0005 decision 6 requires this opt-out to be DECLARED ON THE MODEL, not
            // left as silence, so that "deliberately unscoped" and "someone forgot" stay
            // distinguishable. A SecurityEvent model with no declaration must fail the
            // guard test like any other model. conventions.md §2, fourth carve-out.
            $table->foreignId('company_id')->nullable()->constrained('companies');

            // APPEND-ONLY: created_at alone. No updated_at, no updated_by, no soft deletes
            // (BR-AT6, a documented exception to conventions.md §3). The ONLY process that
            // removes a row is the BR-AT11 retention sweep — a scheduled command with one
            // fixed predicate, taking no filter arguments and reachable only from the
            // scheduler.
            //
            // ⚠ The WRITE to this table is non-blocking and lives OUTSIDE any transaction
            // (BR-AT8). A failure is caught, written to the application file log, and
            // swallowed as far as the request is concerned. Authentication must not depend
            // on a table write, or one database problem makes the system impossible to log
            // into — including for the Master Admin who has to log in to repair it.
            //
            // It follows that THE THROTTLE COUNTER NEVER READS THIS TABLE. BR-A3's tiers
            // are the Auth module's, keyed on the account; a counter derived from these
            // rows would fail OPEN on exactly the fault that suppresses the log.
            $table->timestamp('created_at')->useCurrent();

            // The per-account history, and the retention sweep, which is exactly
            // WHERE user_id IS NULL AND created_at < :cutoff.
            $table->index(['user_id', 'created_at']);

            // Repeated attempts against one number that matches no account.
            $table->index(['identifier', 'created_at']);

            // "All lockouts this month."
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
