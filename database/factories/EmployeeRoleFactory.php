<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\User;
use Database\Factories\Concerns\AttributesAuthorship;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeRole>
 */
class EmployeeRoleFactory extends Factory
{
    use AttributesAuthorship;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),

            // The company this role applies IN — a real company reference, not a tenant
            // marker. It need not equal the employee's own company_id: a person may hold a
            // Manager role at AIM while employed by AHS (adr/0003 decision 4).
            'company_id' => Company::factory(),

            'role' => fake()->randomElement(EmployeeRole::ROLES),
            'effective_date' => now()->toDateString(),
            'assigned_by' => User::factory(),

            // NULL = currently held.
            'revoked_date' => null,
            'revoked_by' => null,
            'revoke_reason' => null,
        ];
    }

    /**
     * ⚠ FIXTURES ENTER RestrictedRoleContext DELIBERATELY — BR-16, `adr/0003` decision 3.
     *
     * `EmployeeRole`'s creating hook refuses ACCOUNT, HR, ASSISTANT_DIRECTOR and HOD unless
     * an authenticated Master Admin is granting them. A factory has no authenticated user, so
     * without this every fixture that needs an HR to test something would have to log a
     * Master Admin in first — turning the guard into an obstacle tests route around, which is
     * how guards get weakened until they are switched off.
     *
     * This is the shortcut being taken ON PURPOSE and in ONE place, which is the whole
     * argument for the context class existing rather than a `runningInConsole()` exemption.
     *
     * ⚠ WHAT THIS MEANS FOR TESTS OF BR-16 ITSELF: they must NOT use this factory for the
     * attempt under test. A test asserting "HR cannot grant ACCOUNT" has to go through
     * `EmployeeRole::create()` or `GrantRole`, or it proves only that the factory bypass
     * works. Building the actors with the factory is fine; performing the grant with it is
     * not.
     */
    protected function createAttributed($attributes = [], ?\Illuminate\Database\Eloquent\Model $parent = null)
    {
        return app(\App\Services\Auth\RestrictedRoleContext::class)->run(
            'Test and seed fixtures build role rows directly. BR-16 governs application grants, '
            .'which go through GrantRole and the EmployeeRole creating hook.',
            fn () => parent::create($attributes, $parent)
        );
    }

    public function role(string $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $role,
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->id,
        ]);
    }

    /**
     * A revoked row.
     *
     * ⚠ Rows created by this state are INVISIBLE to ordinary queries — EmployeeRole applies
     * NotRevokedScope by default. That is the point: this state exists so tests can prove
     * revoked authority does not leak through as current, which adr/0003 decision 1 calls a
     * silent security failure rather than an error.
     *
     * To read them back, use EmployeeRole::withRevoked() or onlyRevoked().
     */
    public function revoked(?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_date' => now()->toDateString(),
            'revoked_by' => User::factory(),
            'revoke_reason' => $reason ?? 'Revoked in test fixture',
        ]);
    }
}
