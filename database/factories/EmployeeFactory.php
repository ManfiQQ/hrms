<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Always the AHS prefix regardless of employing subsidiary — a single group-wide
            // sequence, not per-company (adr/0003 decision 9).
            'employee_no' => 'AHS-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 4, '0', STR_PAD_LEFT),

            'previous_employee_id' => null,
            'full_name' => fake()->name(),
            'nickname' => null,

            // Null by default: most of this workforce has no company email, which is why
            // login runs on the phone number, which lives on users (adr/0006, BR-A1).
            'email' => null,

            // ⚠ No phone_no. The login username lives on the ACCOUNT, not the employee
            // record (adr/0006) — UserFactory generates it. An employee has no username of
            // its own, and there is no separate contact number either (adr/0006 decision 7).

            'company_id' => Company::factory(),

            // branch_id and position_id are nullable and default to null — not every
            // employee has a fixed place of work or a titled position. department_id is
            // NOT NULL because approval routing resolves per (department, company).
            'branch_id' => null,
            'department_id' => Department::factory(),
            'position_id' => null,

            'fingerprint_id' => null,

            // Display only — never drives an authorization or routing decision
            // (adr/0001 decision 1). Authority comes from employee_roles.
            'level' => 'STAFF',

            'employment_type' => 'FULL-TIME',
            'staff_status' => 'ACTIVE',

            'join_date' => fake()->dateTimeBetween('-5 years', '-1 month')->format('Y-m-d'),
            'probation_end_date' => null,
            'confirmation_date' => null,

            'direct_supervisor_id' => null,
            'manager_id' => null,

            'attendance_type' => 'FIXED',
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',

            // Set because attendance_type is FIXED. The service layer requires it in that
            // case; see flexible() for the null case.
            'ot_after_time' => '18:00:00',

            'working_days' => ['MON', 'TUE', 'WED', 'THU', 'FRI'],
            'offday' => ['SAT', 'SUN'],
            'hours_enabled' => false,
        ];
    }

    /** Employed by a specific company. */
    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->id,
        ]);
    }

    /**
     * FLEXIBLE attendance — overtime is applied manually, so ot_after_time is NULL.
     *
     * NULL here means "not applicable", not "unknown" and not "zero". A real-looking value
     * would be read by future code as a genuine OT threshold, silently computing overtime
     * for someone whose OT is decided by a human.
     */
    public function flexible(): static
    {
        return $this->state(fn (array $attributes) => [
            'attendance_type' => 'FLEXIBLE',
            'ot_after_time' => null,
        ]);
    }

    /**
     * Terminal status. Setting this in real code freezes the account and revokes every
     * employee_roles row in the same transaction (adr/0004 decision 5) — the factory only
     * sets the column, since none of that machinery exists yet.
     */
    public function resigned(): static
    {
        return $this->state(fn (array $attributes) => [
            'staff_status' => 'RESIGNED',
        ]);
    }

    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'staff_status' => 'TERMINATED',
        ]);
    }
}
