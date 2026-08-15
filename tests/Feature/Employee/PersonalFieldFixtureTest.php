<?php

use App\Models\Employee;
use App\Models\Nationality;
use App\Models\User;
use Database\Seeders\NationalitySeeder;

/**
 * What the fixtures for `adr/0013`'s columns must guarantee — the factories, not the schema.
 *
 * ⚠ A FIXTURE DEFECT IS THE EXPENSIVE KIND. It fails somewhere other than where it is wrong,
 * and it fails intermittently, so the test that breaks is rarely the test that needs fixing.
 * Both rules below were live defects rather than hypotheticals: the first collided on the
 * second draw when reproduced by hand, and the second would have quietly seeded ages a Payroll
 * test could never see in production.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->masterAdmin()->create());
});

/**
 * ⚠ FAKER'S `unique()` IS PER GENERATOR AND KNOWS NOTHING ABOUT THE DATABASE, which is the
 * whole defect: rows that arrived by seeder are invisible to it.
 *
 * The RNG is seeded and rewound so this is deterministic rather than probable. A test that
 * merely created many rows and hoped for a clash would be a flaky guard for a flaky bug —
 * which is the one combination worse than no guard at all.
 */
it('never draws a nationality name the table already holds', function () {
    fake()->seed(20260814);
    $first = fake()->unique(true)->country();

    // Straight into the table, NOT through the factory — this stands for the seeded starting
    // set, which is exactly the source Faker cannot see.
    Nationality::create(['name' => $first]);

    // Rewind the generator to the same point and clear its memory of the draw, so the
    // factory's first attempt is that same country.
    fake()->seed(20260814);
    fake()->unique(true);

    $drawn = Nationality::factory()->create();

    expect($drawn->name)->not->toBe($first);

    // Leave the generator random again for whatever runs next in this process.
    fake()->seed();
    fake()->unique(true);
});

/** The realistic case the deterministic one stands in for: fixtures on top of a seeded set. */
it('creates fixtures alongside the seeded starting set without collision', function () {
    $this->seed(NationalitySeeder::class);

    Nationality::factory()->count(40)->create();

    expect(Nationality::withTrashed()->count())->toBe(50)
        ->and(Nationality::withTrashed()->pluck('name')->duplicates())->toBeEmpty();
});

/**
 * ⚠ 18 TO 60, BECAUSE THE BOUNDS ARE WHAT PHASE 2 WILL RELY ON. SOCSO's contribution rate
 * changes at 60 and EIS eligibility turns on age, so a payroll test built on these fixtures
 * must not be computing against an age that cannot occur. Faker's untouched default would
 * happily produce a three-year-old employee, which passes every constraint this table has.
 *
 * The lower bound is the law — the Employment Act does not permit an employee record below 18.
 */
it('generates a working-age date of birth by default', function () {
    $ages = collect(Employee::factory()->count(20)->create())
        ->map(fn (Employee $employee) => $employee->date_of_birth->age);

    expect($ages->min())->toBeGreaterThanOrEqual(18)
        ->and($ages->max())->toBeLessThanOrEqual(60);
});

/**
 * ⚠ PAST THE BOUNDARY, NOT ON IT. "Aged over 60" and "turns 60 today" are different questions,
 * and Payroll has not decided the second. A state that landed exactly on the birthday would
 * settle it by accident, and a test written against SOCSO's rate change would pass or fail on
 * a rule nobody wrote.
 */
it('places agedOver past the boundary it names', function () {
    $employee = Employee::factory()->agedOver(60)->create();

    // ⚠ `startOfDay()`, AND THIS WAS FOUND BY WATCHING THE BREAK COME BACK GREEN. Written as
    // isBefore(now()->subYears(60)) the assertion compared a date-cast column at midnight
    // against a time-of-day sixty years ago, so a birth date landing EXACTLY on the birthday
    // still read as "before" — by a few hours, not by a day. The guard passed while the state
    // it guards was broken (conventions.md §9).
    expect($employee->date_of_birth->age)->toBe(60)
        ->and($employee->date_of_birth->lessThan(now()->subYears(60)->startOfDay()))->toBeTrue();
});
