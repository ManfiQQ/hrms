<?php

namespace App\Actions\Auth;

use App\Exceptions\Auth\MasterAdminLimitException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * BR-A13's floor — the last Master Admin cannot be removed, auth-rbac.spec.md §5.8.
 *
 * ⚠ REMOVAL IS A DEMOTION TO `VIEW_ONLY`, NOT A DELETION, and that is a decision worth
 * stating rather than discovering.
 *
 * The row cannot simply be deleted. `audit_logs.user_id` references `users`, and this account
 * has spent its life doing the things most worth being able to account for — granting
 * restricted roles, changing `system_access`, bypassing tenant scope. Deleting it would
 * either be refused by the foreign key or, if the column were nulled, would erase the actor
 * from records that exist precisely to name one. `audit_logs` is kept forever (BR-AT11) and
 * an actorless row is a row that has lost the only thing it was for.
 *
 * ⚠ `VIEW_ONLY` rather than `STANDARD`, and the difference is structural. `STANDARD` derives
 * its read scope from the account's employer — and this account has no employee record, so it
 * would throw `OrphanedAccountException` on the next scoped read. That exception exists to
 * mark data corruption; deliberately creating the state it flags would make it meaningless.
 * `VIEW_ONLY` is defined for exactly an account with no employee that reads group-wide and
 * writes nothing (`adr/0004` decision 2), which is what a stood-down administrator is.
 *
 * What the demotion actually removes: the tenant-scope bypass, every write, the ability to
 * grant restricted roles, and the ability to create or remove another Master Admin.
 */
class RemoveMasterAdmin
{
    public const AUDITS = [
        User::class => ['system_access'],
    ];

    /** BR-A13's floor. */
    public const MINIMUM = 1;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws MasterAdminLimitException
     */
    public function execute(User $target, User $actor, ?string $reason = null): void
    {
        if (! $target->isMasterAdmin()) {
            throw MasterAdminLimitException::notAMasterAdmin();
        }

        // ⚠ Checked before the count, because it fails for a different reason. Removing your
        // own access mid-session leaves a page that appears to work and refuses every action,
        // and the account cannot undo it — so it is refused even when three exist.
        if ($target->is($actor)) {
            throw MasterAdminLimitException::cannotRemoveSelf();
        }

        DB::transaction(function () use ($target, $reason) {
            // Locked for the same reason the ceiling is: two concurrent removals must not
            // both read "two remaining" and both proceed, leaving none.
            $remaining = User::query()->where('system_access', 'FULL')->lockForUpdate()->count();

            if ($remaining <= self::MINIMUM) {
                throw MasterAdminLimitException::lastRemaining();
            }

            $target->forceFill(['system_access' => 'VIEW_ONLY'])->save();

            // Sessions end with the privilege. Leaving them alive would give a stood-down
            // administrator a window in which the page still offers operations the account
            // may no longer perform.
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $target->getKey())
                ->delete();

            $this->audit->record(
                action: 'master_admin.removed',
                subject: $target,
                field: 'system_access',
                old: 'FULL',
                new: 'VIEW_ONLY',
                reason: $reason ?? "Master Admin stood down; {$remaining} existed before this change.",
            );
        });
    }
}
