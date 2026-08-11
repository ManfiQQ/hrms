<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /** Company-dedicated by default. Use shared() for the NULL case. */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->city(),
            'address' => fake()->address(),
        ];
    }

    /**
     * Shared across all companies — company_id NULL (adr/0002 decision 1). The Logistics
     * branch, where AIM, TURSENIA and ES SOFEEYA staff work side by side, is this case.
     */
    public function shared(): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => null,
        ]);
    }
}
