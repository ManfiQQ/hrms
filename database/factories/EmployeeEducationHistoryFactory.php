<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeEducationHistory;
use Database\Factories\Concerns\AttributesAuthorship;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeEducationHistory>
 */
class EmployeeEducationHistoryFactory extends Factory
{
    use AttributesAuthorship;

    protected $model = EmployeeEducationHistory::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),

            // Descriptive table: the tenant marker is denormalized from the parent and the two
            // are equal by definition. See EmployeeFamilyMemberFactory for the full reasoning.
            'company_id' => fn (array $attributes) => Employee::withoutGlobalScopes()
                ->findOrFail($attributes['employee_id'])->company_id,

            // ⚠ ACADEMIC level, not the org seniority tier on `employees`. Same word, unrelated
            // columns — and a string here rather than an enum, because the real list is open.
            'level' => fake()->randomElement(['SPM', 'STPM', 'Diploma', 'Degree']),

            'institution' => fake()->company(),
            'year' => fake()->numberBetween(1990, 2024),
        ];
    }

    /** Attached to a specific employee, with the tenant marker copied from them. */
    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
        ]);
    }

    public function atLevel(string $level): static
    {
        return $this->state(fn (array $attributes) => [
            'level' => $level,
        ]);
    }
}
