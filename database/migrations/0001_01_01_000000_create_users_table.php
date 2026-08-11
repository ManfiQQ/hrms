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

            // Nullable, unique retained. Email is NOT a login credential here — the
            // username is employees.phone_no (auth-rbac.spec.md BR-A1). Most field staff
            // have no company email, and a users row is created for every employee
            // (BR-A20), so NOT NULL would fail on the second such employee. MySQL allows
            // many NULLs under a unique index: optional, but unique where present.
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

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

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
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
