<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeRole>
 */
class EmployeeRoleFactory extends Factory
{
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
