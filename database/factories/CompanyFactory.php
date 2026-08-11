<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * A parent company by default — parent_company_id is null.
     *
     * The default is the parent rather than a subsidiary because read scope derives from
     * this column, and a test that forgets to set it should get the *broader* case openly
     * rather than a subsidiary that quietly looks correct (adr/0004 decision 1).
     */
    public function definition(): array
    {
        return [
            'name' => strtoupper(fake()->unique()->company()),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'parent_company_id' => null,
            'status' => 'ACTIVE',
        ];
    }

    /**
     * A subsidiary under the given parent, or under a newly created one.
     *
     * This is the distinction read scope turns on: an employee of the parent reads the
     * whole group, an employee of a subsidiary reads that subsidiary only.
     */
    public function subsidiary(?Company $parent = null): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_company_id' => $parent?->id ?? Company::factory(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'INACTIVE',
        ]);
    }
}
