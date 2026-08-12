<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeStatusHistory>
 */
class EmployeeStatusHistoryFactory extends Factory
{
    protected $model = EmployeeStatusHistory::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),

            // ⚠ Set independently of the employee's CURRENT company on purpose. This is a
            // frozen event fact — the employer at the time — so a factory that always copied
            // the employee's present company could never produce the row shape that matters
            // most in tests: a pre-transfer row carrying the old company id
            // (adr/0003 decision 7).
            'company_id' => Company::factory(),

            'change_type' => 'STAFF_STATUS',
            'old_value' => 'PROBATION',
            'new_value' => 'CONFIRMED',

            // The display text as it read AT THE TIME. Redundant for enums, which is
            // accepted — one uniform row shape beats per-type branching in every reader.
            'old_label' => 'PROBATION',
            'new_label' => 'CONFIRMED',

            // Distinct from created_at: a promotion is typically effective before HR gets to
            // enter it.
            'effective_date' => now()->toDateString(),

            'reason' => null,
            'changed_by' => null,
        ];
    }

    /** A row belonging to the employee's own company — the ordinary case. */
    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
        ]);
    }

    /**
     * A row frozen under a PREVIOUS employer — the state a company transfer leaves behind.
     *
     * ⚠ This is the state the §2 carve-out exists for: read through the employee
     * relationship these rows must still appear, and queried directly for reporting they
     * must not leak into the new employer's numbers.
     */
    public function frozenUnder(Company $formerEmployer): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $formerEmployer->id,
        ]);
    }

    public function ofType(string $changeType): static
    {
        return $this->state(fn (array $attributes) => [
            'change_type' => $changeType,
        ]);
    }

    public function effectiveOn(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_date' => $date,
        ]);
    }
}
