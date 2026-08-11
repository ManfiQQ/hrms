<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * A STANDARD staff account by default.
     *
     * Three departures from Laravel's scaffolding, each matching a decision:
     *
     *   - email is NULL, not a generated address. Email is not a login credential here —
     *     the username is employees.phone_no (BR-A1) — and most of this workforce has none.
     *     Generating one for every user makes the empty case the unusual one in tests,
     *     which is backwards.
     *   - email_verified_at is NULL. There is no email verification flow in this system, so
     *     a verified timestamp asserts something that never happened.
     *   - No remember_token. The column does not exist; remember-me is removed entirely,
     *     checkbox and driver both (BR-A4).
     *
     * must_change_password is left at its database default of true, so a factory-made
     * account is gated exactly as a provisioned one is.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => null,
            'email_verified_at' => null,
            'password' => static::$password ??= Hash::make('password'),
            'employee_id' => null,
            'system_access' => 'STANDARD',
        ];
    }

    /** Attach this account to an employee, as every staff account is (BR-A20). */
    public function forEmployee(?Employee $employee = null): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => $employee?->id ?? Employee::factory(),
        ]);
    }

    /**
     * A Master Admin account.
     *
     * system_access = FULL with a null employee_id is the single mechanism that identifies
     * one — there is no is_master_admin column, and none may be added (adr/0004 decision 2).
     * The null employee_id is structural: the account has nothing of its own to approve, so
     * there is no self-approval path to forget to guard (adr/0001 decision 4).
     */
    public function masterAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => null,
            'system_access' => 'FULL',
        ]);
    }

    /**
     * A read-only group-wide account.
     *
     * Defined but currently unheld in production — the Director holds a Master Admin account
     * instead (adr/0004 decision 4). Retained for an external auditor or a second Director
     * without write access.
     */
    public function viewOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => null,
            'system_access' => 'VIEW_ONLY',
        ]);
    }

    /** An account that has already completed its first-login password change. */
    public function passwordChanged(): static
    {
        return $this->state(fn (array $attributes) => [
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    /** Give the account an email address. Optional data, never a credential. */
    public function withEmail(?string $email = null): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => $email ?? fake()->unique()->safeEmail(),
        ]);
    }
}
