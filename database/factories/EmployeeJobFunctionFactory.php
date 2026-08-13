<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeJobFunction;
use App\Models\JobFunction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeJobFunction>
 */
class EmployeeJobFunctionFactory extends Factory
{
    protected $model = EmployeeJobFunction::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),

            // ⚠ A COMPANY REFERENCE, not a tenant marker — it says where the person performs
            // this function. It defaults to the employee's own company because that is the
            // ordinary case, NOT because the two must agree: they routinely do not, and
            // atCompany() below is the case employee-master.spec.md BR-12 renders as
            // "Also serving at". A transfer leaves this column untouched (adr/0003 decision 7).
            'company_id' => fn (array $attributes) => Employee::withoutGlobalScopes()
                ->findOrFail($attributes['employee_id'])->company_id,

            'job_function_id' => JobFunction::factory(),
        ];
    }

    /** Performing a function at their own employer — the ordinary case. */
    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
        ]);
    }

    /**
     * Performing a function at a group company that does NOT employ them.
     *
     * ⚠ This is the row shape that proves the column is a reference rather than a tenant
     * marker. A test using only forEmployee() cannot tell the two apart, because there the
     * two columns agree by coincidence.
     */
    public function atCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->id,
        ]);
    }

    public function performing(JobFunction $function): static
    {
        return $this->state(fn (array $attributes) => [
            'job_function_id' => $function->id,
        ]);
    }
}
