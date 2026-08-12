<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-A3's throttle state — auth-rbac.spec.md §3.
 *
 * ⚠ These columns were missing. The spec described four tiers and a counter that resets on
 * success, and gave them nowhere to live; the rule was unimplementable as written.
 *
 * ⚠ THEY ARE DATABASE COLUMNS, NOT CACHE ENTRIES, and that is the point. The fourth tier is
 * a PERMANENT lock that only HR or Master Admin may lift. A counter in the cache would be
 * erased by `php artisan cache:clear` — a routine deploy step — which would silently unlock
 * every permanently locked account in the group, leave no trace, and look exactly like
 * nothing happening.
 *
 * That matters more here than in most systems: the username is not secret (it is the
 * employee's phone number) and the password minimum is six characters, chosen by the client
 * over the recommended eight. Throttling is not defence in depth — it is the defence. Its
 * state has to be as durable as the account itself.
 *
 * ⚠ This is not the legacy "repair migration" pattern conventions.md §7 warns about. That
 * pattern is a column that should have been in the original design being bolted on after
 * real data exists. Here the base `users` table predates the Auth module having a spec at
 * all, the gap was found while implementing against that spec rather than after shipping,
 * and no production data exists. The column is added before the first line of throttle code,
 * not after a bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Cumulative failures since the last successful login. BR-A3 resets it on
            // success — without that, three typos spread over months would eventually lock
            // someone out.
            $table->unsignedInteger('failed_login_attempts')->default(0)->after('password_changed_at');

            // The current TIMED lock (tiers 1-3). ⚠ NULL DOES NOT MEAN "NOT LOCKED" — it
            // means no timed lock is in force. The permanent lock is failed_login_attempts
            // having reached the fourth tier's threshold, and it is checked first.
            //
            // There is deliberately NO locked_permanently boolean. It would be a second way
            // to say "locked", and every unlock path would then have to clear both — the
            // path that clears only one is a silent hole. Same reasoning that keeps
            // is_enabled off employee_roles (adr/0003 decision 1) and is_master_admin off
            // this table (adr/0004 decision 2).
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'locked_until']);
        });
    }
};
