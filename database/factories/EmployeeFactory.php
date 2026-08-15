<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Nationality;
use Database\Factories\Concerns\AttributesAuthorship;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    use AttributesAuthorship;

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

            // ⚠ 18 TO 60, AND THE BOUNDS ARE THE POINT RATHER THAN TIDINESS (adr/0013
            // decision 1). Faker's default date range would happily produce a three-year-old
            // employee, which passes every constraint this table has and then quietly poisons
            // Phase 2: SOCSO's contribution rate CHANGES AT 60 and EIS eligibility turns on
            // age, so a payroll test built on these fixtures would compute against ages that
            // cannot occur. The lower bound is the law — the Employment Act does not permit an
            // employee record below 18 — and a fixture that generates one asserts something
            // the business may not do.
            //
            // ⚠ The range STOPS at 60 on purpose: no default fixture should land on the far
            // side of the SOCSO boundary by accident. A test that wants that case says so, with
            // agedOver() below.
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),

            'gender' => fake()->randomElement(['MALE', 'FEMALE']),

            // ⚠ Reuses an existing nationality before creating one. The vocabulary is
            // group-wide and ten rows deep in reality — thousands of employees share them — so
            // a fresh country per fixture would misrepresent the table and drain the pool of
            // unused country names NationalityFactory draws from.
            'nationality_id' => Nationality::query()->value('id') ?? Nationality::factory(),

            // The nine nullable identity and statutory columns default to null and are not
            // listed here. ⚠ That is deliberate for `ic_no` and `passport_no`: a FormRequest
            // will require at least one of the two (adr/0013 decision 2), and a factory that
            // always supplied an IC would make every test satisfy that rule without meaning
            // to — including the tests written to prove it is enforced.

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
     * Past a given age on a given day — the boundary cases the default range excludes.
     *
     * ⚠ WRITTEN FOR SOCSO, WHOSE CONTRIBUTION RATE CHANGES AT 60 (adr/0013 decision 1). A
     * Payroll test arranging that case by hand would put a raw date string in an array, and the
     * reader would have to compute an age from it to see which rule was being exercised;
     * `->agedOver(60)` says it. The default range stops at 60 precisely so no fixture lands on
     * the far side by accident, which would make such a test pass for the wrong reason.
     *
     * A day past the birthday, never exactly on it: "aged over 60" and "turns 60 today" are
     * different questions, and a fixture that answered both would settle by accident a rule
     * Payroll has not yet decided.
     */
    public function agedOver(int $years): static
    {
        return $this->state(fn (array $attributes) => [
            'date_of_birth' => now()->subYears($years)->subDay()->format('Y-m-d'),
        ]);
    }

    /** A named nationality, where a test cares which — otherwise the default reuses any. */
    public function ofNationality(Nationality $nationality): static
    {
        return $this->state(fn (array $attributes) => [
            'nationality_id' => $nationality->id,
        ]);
    }

    /**
     * Reports to this employee through `direct_supervisor_id` (BR-8).
     *
     * ⚠ Added 2026-08-14 with `adr/0011`, and it is not a convenience. The reporting line is
     * now the SUPERVISORY READ BOUND — an employee whose two FKs are null is read by nobody
     * below `HR` (decision 4) — so a fixture that leaves them null is a fixture asserting
     * invisibility, whether or not it meant to. Setting them by hand inside a test hides that
     * behind an array key; a named state says which rule is being arranged.
     *
     * The column is nullable and stays so. Every chain ends somewhere.
     */
    public function reportingTo(Employee $supervisor): static
    {
        return $this->state(fn (array $attributes) => [
            'direct_supervisor_id' => $supervisor->id,
        ]);
    }

    /**
     * Reports to this employee through `manager_id` — the second of BR-8's two tiers.
     *
     * Separate from reportingTo() because `adr/0011` decision 1 accepts EITHER column, and a
     * state that set both could never show that one alone is enough.
     */
    public function managedBy(Employee $manager): static
    {
        return $this->state(fn (array $attributes) => [
            'manager_id' => $manager->id,
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
