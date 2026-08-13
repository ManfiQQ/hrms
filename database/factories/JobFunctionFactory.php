<?php

namespace Database\Factories;

use App\Models\JobFunction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobFunction>
 */
class JobFunctionFactory extends Factory
{
    protected $model = JobFunction::class;

    public function definition(): array
    {
        return [
            // ⚠ UNIQUE, because the column is. A factory repeating a name would fail on the
            // second row with a constraint violation rather than a readable message, and the
            // uniqueness is deliberate: it reserves a soft-deleted function's name so the only
            // way back is restoring the original row, carrying its assignments with it.
            'name' => ucwords(fake()->unique()->words(2, true)),

            'description' => null,

            // ⚠ No company_id, and none may be added. One group-wide vocabulary, owned by
            // Master Admin — six per-company lists is how one thing acquires three spellings
            // (CLAUDE.md §5).
        ];
    }

    /** One of the starting set — BDO, Admin, Media, Live Host, Operation Crew. */
    public function named(string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $name,
        ]);
    }

    /**
     * A withdrawn function.
     *
     * ⚠ Deactivation IS the soft delete — there is no `is_active` column, and this state
     * exists partly so nobody reintroduces one looking for a way to express it. Existing
     * `employee_job_functions` rows stay intact and readable; restore() brings it back.
     */
    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
