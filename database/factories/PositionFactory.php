<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    /**
     * ⚠ NO `company_id`, AND NO `shared()` STATE — `positions` IS NOT SHAPED LIKE ITS TWO
     * SIBLINGS, and reaching for the familiar pattern here would invent a column.
     *
     * `branches` and `departments` each carry a NULLABLE `company_id` where NULL means
     * *shared across all companies* (`adr/0002` decision 1), and both factories offer a
     * `shared()` state for exactly that case. **`positions` carries no `company_id` at all** —
     * not nullable, absent. A position hangs off a department, and the department is what
     * answers which company (or companies) it belongs to.
     *
     * The consequence is worth stating because it looks like an omission: `Position` declares
     * no tenant scope and no `TENANT_SCOPE_EXEMPT`, and `TenantScopeGuardTest` skips it by
     * there being no column to scope — the same footing as `Sequence` and `JobFunction`, not
     * an opt-out.
     *
     * ⚠ A shared department therefore makes its positions shared too, implicitly. That is the
     * design, not a gap: "Senior Executive in HQ Marketing" is one title however many
     * companies staff that department.
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'title' => fake()->unique()->jobTitle(),
        ];
    }

    /** A position inside a specific department. */
    public function inDepartment(?Department $department = null): static
    {
        return $this->state(fn (array $attributes) => [
            'department_id' => $department?->id ?? Department::factory(),
        ]);
    }

    public function titled(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
        ]);
    }

    /**
     * A position in a department dedicated to one company — the ordinary case, and the one to
     * reach for when a test needs the position to sit inside a single tenant.
     *
     * ⚠ The scoping lives on the DEPARTMENT. This state is a convenience for expressing that,
     * never a hint that `positions` has a company of its own.
     */
    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'department_id' => Department::factory()->create(['company_id' => $company->id])->id,
        ]);
    }
}
