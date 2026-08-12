<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BR-A5 — expired session rows are pruned on a schedule.
 *
 * Sessions live in the database rather than in files, and that is what makes BR-A15
 * possible: `DELETE FROM sessions WHERE user_id = ?` terminates someone's access
 * immediately. File sessions cannot be located by user without reading every file, so
 * "immediately" would in practice mean "on their next request".
 *
 * The cost of that choice is a table that grows without bound, because Laravel's session
 * garbage collection is a lottery on request — it runs when it happens to run, and a
 * low-traffic period leaves rows behind indefinitely. This command makes the cleanup
 * deterministic.
 *
 * ⚠ Deleting an expired session row is not the deletion CLAUDE.md §3 forbids. That rule
 * protects HR RECORDS, which carry statutory retention. A session row is a transport
 * detail — it holds no employment fact, and the audit trail of who logged in and when lives
 * in security_events, which this never touches.
 */
class PruneExpiredSessions extends Command
{
    protected $signature = 'sessions:prune {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete session rows past the inactivity window (auth-rbac.spec.md BR-A5)';

    public function handle(): int
    {
        if (config('session.driver') !== 'database') {
            $this->warn('Session driver is not "database" — nothing to prune (BR-A5 expects database sessions).');

            return self::SUCCESS;
        }

        // ⚠ Counted from last_activity, not from creation. BR-A6's window is INACTIVITY, not
        // elapsed time since login: someone working through the day is never interrupted,
        // and what expires is a session left open on a shared terminal.
        $lifetime = (int) config('session.lifetime');
        $cutoff = now()->subMinutes($lifetime)->getTimestamp();

        $query = DB::table(config('session.table', 'sessions'))->where('last_activity', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info(sprintf('%d session rows are past the %d-minute window. Nothing deleted.', $query->count(), $lifetime));

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info(sprintf('Pruned %d session rows inactive for more than %d minutes.', $deleted, $lifetime));

        return self::SUCCESS;
    }
}
