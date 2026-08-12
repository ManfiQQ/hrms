<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // ⚠ THE LOGIN USERNAME (adr/0006, auth-rbac.spec.md BR-A1). NOT NULL and UNIQUE:
            // an account without one cannot authenticate, and two accounts sharing one would
            // hand a login to the wrong person. There is no placeholder path — a dummy number
            // occupies the unique index and hands one person's username to another.
            //
            // ⚠ IT LIVES HERE, NOT ON employees, AND THAT IS LOAD-BEARING. It was on
            // `employees` until 2026-08-12, which made the Master Admin account
            // unreachable: BR-A1 makes the number the username and adr/0001 decision 4 gives
            // Master Admin no employee record, so the account had nowhere to keep its own.
            // Reproduced with the correct password — refused on the number, the email, the
            // id and an empty string. It is the first account and the only one, so that was
            // not one broken login but a system nobody could enter.
            //
            // The account is the thing that authenticates, so the username belongs to the
            // account. That is what lets an account with no person still have one.
            //
            // The value is normalised before storing and before comparing (strip spaces,
            // dashes, leading +60 or 60) and validated at 9-12 digits. That belongs to
            // App\Support\Auth\PhoneNumber, not to the schema.
            $table->string('phone_no')->unique();

            // Nullable, unique retained. Email is NOT a login credential here — the
            // username is phone_no above (auth-rbac.spec.md BR-A1). Most field staff have no
            // company email, and a users row is created for every employee (BR-A20), so NOT
            // NULL would fail on the second such employee. MySQL allows many NULLs under a
            // unique index: optional, but unique where present.
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // No rememberToken(). Remember-me is removed entirely — checkbox gone and
            // driver disabled (BR-A4). The column is not merely unused: left in place it
            // reads as "the feature exists, it just isn't wired up yet".

            // Null for Master Admin and Director, which have no employee record
            // (adr/0001 decision 4, adr/0004 decision 4). The foreign key constraint is
            // added by the employees migration, which creates the table this points at.
            $table->unsignedBigInteger('employee_id')->nullable();

            // FULL = Master Admin. VIEW_ONLY = read-only group-wide (defined, currently
            // no holder). STANDARD = everyone else, permissions entirely from
            // employee_roles + derived read scope (adr/0004 decision 2).
            // Defaults to the narrowest value, so an account created by a code path that
            // forgets the column is not a group-wide reader. FULL and VIEW_ONLY are never
            // reached by omission.
            $table->enum('system_access', ['FULL', 'VIEW_ONLY', 'STANDARD'])
                ->default('STANDARD');

            // Default true so an account created by a code path that forgets the flag is
            // gated, not exposed (adr/0001 decision 5, BR-A23).
            $table->boolean('must_change_password')->default(true);
            $table->timestamp('password_changed_at')->nullable();

            // Single-use QR activation, not a temporary password (adr/0004 decision 7).
            // downloaded_at is set automatically when HR fetches the image — the system
            // records what it can observe, never an assertion that it was sent (BR-A22).
            $table->string('activation_token')->nullable()->unique();
            $table->timestamp('activation_expires_at')->nullable();
            $table->timestamp('activation_downloaded_at')->nullable();
            $table->timestamp('activation_used_at')->nullable();

            $table->timestamps();
        });

        // No password_reset_tokens table. Password reset is not self-service by email —
        // it is performed by HR or Master Admin from the account management screen
        // (BR-A7). Most of this workforce has no email address to send a link to, so the
        // table would never hold a row, and an unused table reads as an unfinished feature.

        // Sessions are stored in the database, not in files (BR-A5). This is what makes
        // BR-A15 possible: DELETE FROM sessions WHERE user_id = ? ends access immediately
        // when staff_status becomes TERMINATED. File sessions cannot be located by user
        // without reading every file, so "immediately" would mean "on their next request".
        // user_id carries an index for exactly that query.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
