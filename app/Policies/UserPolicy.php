<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Auth\ReadScopeResolver;
use App\Services\Auth\RoleChecker;

/**
 * Authorisation for account operations — conventions.md §1 puts these in a Policy, never
 * inline in a controller.
 */
class UserPolicy
{
    public function __construct(
        private readonly RoleChecker $roles,
        private readonly ReadScopeResolver $scope,
    ) {}

    /**
     * May this account fetch another account's activation image?
     *
     * ⚠ THE IMAGE IS A CREDENTIAL, not a report. Whoever holds it can activate the account:
     * redemption authenticates the holder outright and lets them set the first password
     * (adr/0004 decision 7). So this is not "may you see this employee" — it is "may you
     * take possession of their account", and it is deliberately narrower than the read scope
     * that governs ordinary employee data.
     *
     * `HR` and Master Admin only (auth-rbac.spec.md §6). ⚠ `ASSISTANT_DIRECTOR` is excluded
     * even though it may create, edit and archive employee records: account operations and
     * profile operations are separate, and an account holder who could hand out activations
     * but could not reset a password is a combination that makes sense from no direction.
     * `ACCOUNT` reads the most data in the system and administers none of it.
     */
    public function viewActivationImage(User $actor, User $target): bool
    {
        if ($actor->isMasterAdmin()) {
            return true;
        }

        $scope = $this->scope->resolve($actor);

        // ⚠ Read scope bounds WHICH accounts, and it is checked independently of the role.
        // A subsidiary-employed HR holds HR across the group's approval chain but reads one
        // company — the two axes disagree by design (conventions.md §2), and handing out
        // activations follows the reading axis, because it is possession of a specific
        // person's account.
        $employerId = $target->employee?->company_id;

        if ($employerId === null || ! in_array($employerId, $scope, true)) {
            return false;
        }

        // Holding HR anywhere in scope authorises the operation; the check above is what
        // bounds it to this employee. Same shape as AuditLogReader's authorisation.
        foreach ($scope as $companyId) {
            if ($this->roles->hasRole($actor, 'HR', $companyId)) {
                return true;
            }
        }

        return false;
    }
}
