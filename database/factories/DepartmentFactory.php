<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Company-dedicated by default. Use shared() for the NULL case.
     *
     * The default is dedicated rather than shared so that a test which does not think about
     * sharing gets ordinary tenant behaviour, and the carve-out has to be asked for.
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'branch_id' => null,
            'name' => fake()->unique()->word(),
        ];
    }

    /**
     * Shared across all companies — company_id NULL (adr/0002 decision 1).
     *
     * ⚠ This is the state that catches the bug the carve-out invites: a query written as
     * `where company_id = :current` silently drops these rows rather than erroring.
     */
    public function shared(): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => null,
        ]);
    }

    public function inBranch(?Branch $branch = null): static
    {
        return $this->state(fn (array $attributes) => [
            'branch_id' => $branch?->id ?? Branch::factory(),
        ]);
    }
}
