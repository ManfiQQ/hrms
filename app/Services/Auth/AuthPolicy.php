<?php

namespace App\Services\Auth;

use App\Models\Company;
use App\Models\PolicyConfiguration;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use RuntimeException;

/**
 * Reads the authentication numbers from policy_configurations — conventions.md §5.
 *
 * ⚠ NOT ONE NUMBER IS HARDCODED HERE, and that is not a style preference. All six entities
 * share the same values today, which is exactly why they belong in a table: the moment one
 * diverges, code with a literal in it is wrong everywhere at once (conventions.md §5).
 *
 * ⚠ It THROWS on a missing or unusable key rather than falling back to a default. A default
 * compiled in here would be a second source for the number, and the two would disagree the
 * first time the table changed — with the code's copy winning silently. For the throttle
 * tiers specifically, a fallback is worse than an outage: BR-A2's six-character minimum is
 * carried entirely by BR-A3's tiers, so a wrong tier value is a security failure that looks
 * like a working login screen.
 */
class AuthPolicy
{
    /** @var array<string, string> */
    private array $cache = [];

    /**
     * The value of a policy key for this account's employer.
     *
     * Falls back to the PARENT company when the account has no employee — Master Admin and
     * Director belong to no company (adr/0004 decision 4), and an unknown login identifier
     * has no account at all. The parent is the group's own row, so it is the only sensible
     * answer to "what is the policy for nobody in particular".
     */
    public function int(string $key, ?User $user = null): int
    {
        $value = $this->value($key, $user);

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException(
                "policy_configurations key \"{$key}\" is not an integer (got ".var_export($value, true).'). '
                .'Authentication numbers are read from configuration and never guessed '
                .'(conventions.md §5, auth-rbac.spec.md §4).'
            );
        }

        return (int) $value;
    }

    public function value(string $key, ?User $user = null): string
    {
        $companyId = $this->companyIdFor($user);
        $cacheKey = $companyId.'|'.$key;

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        // ⚠ Scope released deliberately. This runs BEFORE authentication — during a login
        // attempt there is no authenticated user from whom TenantScope could resolve a read
        // scope, and the scope would return early and read every company's rows anyway.
        // Releasing it explicitly means the behaviour is the same however it is invoked, and
        // is a stated decision rather than a side effect of who happens to be logged in.
        $value = PolicyConfiguration::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->value('value');

        if ($value === null) {
            throw new RuntimeException(
                "policy_configurations key \"{$key}\" is not set for company {$companyId}. "
                .'Authentication numbers are read from configuration and never hardcoded; '
                .'run PolicyConfigurationSeeder (conventions.md §5).'
            );
        }

        return $this->cache[$cacheKey] = $value;
    }

    /** For tests and long-running processes. */
    public function flush(): void
    {
        $this->cache = [];
    }

    private function companyIdFor(?User $user): int
    {
        $companyId = $user?->employee_id === null
            ? null
            : $user->employee()->withoutGlobalScope(TenantScope::class)->value('company_id');

        if ($companyId !== null) {
            return (int) $companyId;
        }

        $parentId = Company::query()->whereNull('parent_company_id')->value('id');

        if ($parentId === null) {
            throw new RuntimeException(
                'No parent company found (companies.parent_company_id IS NULL). Authentication '
                .'policy values fall back to the parent, and the hierarchy is not seeded.'
            );
        }

        return (int) $parentId;
    }
}
