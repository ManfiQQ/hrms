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
