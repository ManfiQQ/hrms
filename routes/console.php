<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The BR-AT11 retention sweep — audit-trail.spec.md §5.6.
 *
 * ⚠ SCHEDULED, never run on demand from a request. It is the only process permitted to
 * remove a row from either audit table (BR-AT6), and the way it stays that way is by having
 * no route, no controller and no UI affordance — only this line.
 *
 * withoutOverlapping() because a sweep that has not finished must not be started again: two
 * concurrent deletes over the same window are wasted work at best.
 */
Schedule::command('security-events:prune')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * BR-A5 — expired session rows are pruned on a schedule.
 *
 * Database sessions are what make BR-A15's immediate session kill possible, and the cost is
 * a table that grows without bound: Laravel's own garbage collection is a lottery on
 * request, so it runs when it happens to run. Hourly rather than daily, because the table is
 * written on every authenticated request and a day's worth of dead rows is a lot of them.
 */
Schedule::command('sessions:prune')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
