<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BR-A4, BR-A5 and BR-A6 — the session configuration these rules depend on.
 *
 * These read like configuration assertions because that is exactly what they are: three
 * rules whose enforcement is a config value plus the absence of a column, and neither is
 * visible in any service. A regression here is a line in a config file, which is the kind of
 * change that passes review unremarked.
 */
it('stores sessions in the database, which is what makes BR-A15 possible', function () {
    // DELETE FROM sessions WHERE user_id = ? terminates someone's access immediately. File
    // sessions cannot be located by user without reading every file, so "immediately" would
    // in practice mean "on their next request". Redis was rejected on the VPS's RAM
    // constraints, the same reason Coolify was ruled out (CLAUDE.md §3).
    expect(config('session.driver'))->toBe('database')
        ->and(Schema::hasTable('sessions'))->toBeTrue()
        ->and(Schema::hasColumn('sessions', 'user_id'))->toBeTrue();
});

it('indexes sessions by user_id, so BR-A15 does not table-scan to kill a session', function () {
    $indexes = collect(Schema::getIndexes('sessions'))
        ->flatMap(fn (array $index) => [$index['columns']]);

    expect($indexes->contains(fn (array $columns) => in_array('user_id', $columns, true)))->toBeTrue();
});

/**
 * ⚠ BR-A6 is INACTIVITY, not elapsed time since login. Someone working through the day is
 * never interrupted; what expires is a session left open on a shared terminal.
 *
 * That distinction is carried by `last_activity` being rewritten on every request. A driver
 * that stamped only creation time would expire people mid-shift.
 */
it('expires on inactivity by tracking last_activity, not a creation timestamp', function () {
    expect(Schema::hasColumn('sessions', 'last_activity'))->toBeTrue()
        ->and(Schema::hasColumn('sessions', 'created_at'))->toBeFalse()
        ->and(config('session.expire_on_close'))->toBeFalse();
});

it('sets the inactivity window to two hours', function () {
    expect((int) config('session.lifetime'))->toBe(120);
});

/**
 * ⚠ BR-A4. Removing the checkbox from the form is not the same as disabling the feature,
 * because the field can be posted directly.
 *
 * Laravel's default users migration creates rememberToken(); this project's must not, and it
 * may not be added later — an unused column reads as "the feature exists, it just isn't
 * wired up", which is how it gets wired up.
 */
it('has no remember_token column for a recaller to be minted against', function () {
    expect(Schema::hasColumn('users', 'remember_token'))->toBeFalse()
        ->and(config('auth.remember_me.enabled'))->toBeFalse();
});

it('prunes session rows past the inactivity window and keeps active ones', function () {
    $lifetime = (int) config('session.lifetime');

    DB::table('sessions')->insert([
        ['id' => 'stale', 'payload' => '', 'last_activity' => now()->subMinutes($lifetime + 5)->getTimestamp()],
        ['id' => 'active', 'payload' => '', 'last_activity' => now()->subMinutes($lifetime - 5)->getTimestamp()],
    ]);

    $this->artisan('sessions:prune')->assertExitCode(0);

    // Both halves: a prune that dropped its predicate would log everyone out at once.
    expect(DB::table('sessions')->pluck('id')->all())->toBe(['active']);
});

it('reports without deleting on a dry run', function () {
    DB::table('sessions')->insert([
        ['id' => 'stale', 'payload' => '', 'last_activity' => now()->subDays(30)->getTimestamp()],
    ]);

    $this->artisan('sessions:prune --dry-run')->assertExitCode(0);

    expect(DB::table('sessions')->count())->toBe(1);
});

it('schedules the session prune, so the table does not grow without bound', function () {
    // Laravel's own garbage collection is a lottery on request: it runs when it happens to
    // run, and a quiet period leaves rows behind indefinitely.
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command ?? '', 'sessions:prune'));

    expect($events)->toHaveCount(1);
});
