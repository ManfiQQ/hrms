<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\PolicyConfiguration;
use App\Models\Scopes\TenantScope;
use App\Models\SecurityEvent;
use Illuminate\Console\Command;

/**
 * The BR-AT11 retention sweep — audit-trail.spec.md §5.6.
 *
 * ⚠ THE ONLY PROCESS PERMITTED TO REMOVE A ROW FROM EITHER AUDIT TABLE, and the single
 * exception to BR-AT6's append-only rule. One fixed predicate, no filter arguments — a prune
 * command that accepts a --where is a delete capability with extra steps.
 *
 *     DELETE FROM security_events WHERE user_id IS NULL AND created_at < :cutoff
 *
 * ⚠ user_id IS THE DISCRIMINATOR, and it decides the fate of the row:
 *
 *     user_id NOT NULL — an attempt against an account that exists → KEPT FOREVER
 *     user_id NULL     — an attempt against a number in no account → 90 days
 *
 * This does not breach CLAUDE.md §3, which forbids deleting for PERFORMANCE. Nothing here is
 * deleted to make anything faster; the line drawn is between a record and noise. An attempt
 * against a number that has never existed in this system has no subject — no employee, no
 * account, no company — and therefore no statutory retention period, because there is nobody
 * it is about. An attempt against a real account is the opposite on every count.
 *
 * It touches audit_logs NEVER. Those rows are kept forever, without exception.
 */
class PruneSecurityEvents extends Command
{
    protected $signature = 'security-events:prune {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete unattributed security events past their retention window (audit-trail.spec.md BR-AT11)';

    /** The group-level retention setting. See resolveRetentionDays() for why it is read from the parent. */
    public const RETENTION_KEY = 'audit.security_events.unattributed_retention_days';

    public function handle(): int
    {
        $days = $this->resolveRetentionDays();

        if ($days === null) {
            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        // ⚠ The predicate is fixed here and takes nothing from the caller. whereNotNull is
        // the half that protects every row worth keeping, and it is the half a careless
        // rewrite drops — leaving a command that silently deletes the group's entire
        // security history on its next scheduled run.
        $query = SecurityEvent::query()
            ->whereNull('user_id')
            ->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                '%d unattributed security events are older than %d days (before %s). Nothing deleted.',
                $query->count(), $days, $cutoff->toDateTimeString()
            ));

            return self::SUCCESS;
        }

        // ⚠ A query-builder delete, deliberately NOT a model delete: SecurityEvent's
        // `deleting` hook throws, because BR-AT6 forbids removing rows through the model.
        // This command is the documented exception, and going around the model is how it
        // stays the ONLY one — a model method that allowed deletion would be reachable from
        // anywhere.
        $deleted = $query->toBase()->delete();

        // Logged every run, so a sweep that suddenly removes far more than usual is visible.
        $this->info(sprintf(
            'Pruned %d unattributed security events older than %d days (before %s). '
            .'Attributed events were not touched.',
            $deleted, $days, $cutoff->toDateTimeString()
        ));

        return self::SUCCESS;
    }

    /**
     * The retention window, in days, or null when it cannot be trusted.
     *
     * ⚠ Read from the PARENT company's row. This one setting is not per-company, and
     * policy_configurations.company_id is NOT NULL — while the rows being pruned have no
     * company at all, so "per company" has nothing to attach to. Reading the parent
     * (parent_company_id IS NULL) is the group-level answer the schema can express today,
     * and it keeps the number out of code as conventions.md §5 requires. A subsidiary row
     * for this key is IGNORED, not merged: two answers to a group-wide question is the drift
     * this project rejects everywhere else.
     *
     * ⚠ It ABORTS rather than defaulting. A default compiled in here would be a second
     * source for a number that must live in configuration — and the failure mode of guessing
     * is deleting rows that should have been kept.
     */
    private function resolveRetentionDays(): ?int
    {
        // Without a scope release this reads as whoever is authenticated — and on a schedule
        // that is nobody, so the query would be unscoped anyway. Released explicitly so the
        // command behaves identically however it is invoked.
        $parent = Company::query()->whereNull('parent_company_id')->first();

        if ($parent === null) {
            $this->error(
                'No parent company found (companies.parent_company_id IS NULL). The retention '
                .'window is a group-level setting read from the parent, and the hierarchy is '
                .'not seeded. Nothing deleted.'
            );

            return null;
        }

        $value = PolicyConfiguration::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $parent->id)
            ->where('key', self::RETENTION_KEY)
            ->value('value');

        if ($value === null || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            $this->error(sprintf(
                'policy_configurations key "%s" for %s is missing or not a positive integer (got %s). '
                .'Refusing to guess a retention window — nothing deleted (audit-trail.spec.md §5.6).',
                self::RETENTION_KEY,
                $parent->code,
                $value === null ? 'nothing' : var_export($value, true)
            ));

            return null;
        }

        return (int) $value;
    }
}
