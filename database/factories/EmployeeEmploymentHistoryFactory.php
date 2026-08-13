<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeEmploymentHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeEmploymentHistory>
 */
class EmployeeEmploymentHistoryFactory extends Factory
{
    protected $model = EmployeeEmploymentHistory::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),

            // Descriptive table: the tenant marker is denormalized from the parent and the two
            // are equal by definition. See EmployeeFamilyMemberFactory for the full reasoning.
            'company_id' => fn (array $attributes) => Employee::withoutGlobalScopes()
                ->findOrFail($attributes['employee_id'])->company_id,

            // ⚠ A FORMER EMPLOYER OUTSIDE THIS GROUP — a string, never one of the six
            // entities. Generating a group company's name here would describe the one case
            // this table is not for, and quietly invite a future FK.
            'company_name' => fake()->company(),

            // Likewise their job title, not a `positions` row: another company's vocabulary.
            'position' => fake()->jobTitle(),

            'start_date' => fake()->dateTimeBetween('-10 years', '-3 years')->format('Y-m-d'),
            'end_date' => fake()->dateTimeBetween('-3 years', '-1 year')->format('Y-m-d'),
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

    /**
     * Still serving notice at the previous employer — the ordinary state of a record entered
     * at hiring time, and why end_date is nullable.
     */
    public function ongoing(): static
    {
        return $this->state(fn (array $attributes) => [
            'end_date' => null,
        ]);
    }
}
