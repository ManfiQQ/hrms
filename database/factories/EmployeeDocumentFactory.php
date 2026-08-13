<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeDocument>
 */
class EmployeeDocumentFactory extends Factory
{
    protected $model = EmployeeDocument::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),

            // Descriptive table: the tenant marker is denormalized from the parent and the two
            // are equal by definition. See EmployeeFamilyMemberFactory for the full reasoning.
            'company_id' => fn (array $attributes) => Employee::withoutGlobalScopes()
                ->findOrFail($attributes['employee_id'])->company_id,

            'type' => 'IC',

            // ⚠ Unique per row, and not decoration. `file_path` is WRITE-ONCE on this model, so
            // a factory that reused one path across rows would make the replacement flow —
            // new row plus soft delete of the old — indistinguishable from an in-place edit in
            // any test that asserted on the path.
            'file_path' => 'documents/'.fake()->unique()->uuid().'.pdf',
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

    public function ofType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }

    /**
     * The one type the employee may NOT retrieve for themselves (§6.3) — the escape hatch for
     * documents that do not fit the fixed list, and therefore the natural home for internal
     * notes and investigation material.
     */
    public function internal(): static
    {
        return $this->ofType('OTHER');
    }

    public function storedAt(string $path): static
    {
        return $this->state(fn (array $attributes) => [
            'file_path' => $path,
        ]);
    }
}
