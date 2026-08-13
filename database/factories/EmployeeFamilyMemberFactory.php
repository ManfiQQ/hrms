<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeFamilyMember;
use Database\Factories\Concerns\AttributesAuthorship;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeFamilyMember>
 */
class EmployeeFamilyMemberFactory extends Factory
{
    use AttributesAuthorship;

    protected $model = EmployeeFamilyMember::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),

            // ⚠ DERIVED from the employee, not generated independently — the opposite of
            // EmployeeStatusHistoryFactory, and for the opposite reason. This is a DESCRIPTIVE
            // table, so company_id is a tenant marker denormalized from the parent and the two
            // are equal by definition; a transfer cascades it. A factory that generated one
            // freely could produce a row that cannot exist, and tests written against it would
            // pass while describing nothing real.
            //
            // Global scopes are lifted for the lookup because a factory may run while an
            // account is authenticated, and the parent would otherwise be invisible to it.
            'company_id' => fn (array $attributes) => Employee::withoutGlobalScopes()
                ->findOrFail($attributes['employee_id'])->company_id,

            'relationship' => fake()->randomElement(['Spouse', 'Child', 'Father', 'Mother']),
            'name' => fake()->name(),
            'contact_no' => fake()->numerify('01########'),
            'is_emergency_contact' => false,
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
     * The one family record a supervisor may read — name and number only, surfaced on the
     * Employment tab rather than behind Family (employee-master.spec.md §6.2).
     */
    public function emergencyContact(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_emergency_contact' => true,
        ]);
    }

    /** A dependent with no phone of their own — the case that makes contact_no nullable. */
    public function withoutContactNumber(): static
    {
        return $this->state(fn (array $attributes) => [
            'contact_no' => null,
        ]);
    }
}
