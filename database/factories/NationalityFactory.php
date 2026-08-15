<?php

namespace Database\Factories;

use App\Models\Nationality;
use Database\Factories\Concerns\AttributesAuthorship;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<Nationality>
 */
class NationalityFactory extends Factory
{
    use AttributesAuthorship;

    protected $model = Nationality::class;

    public function definition(): array
    {
        return [
            'name' => $this->unusedCountryName(),

            // ⚠ No company_id, and none may be added. One group-wide vocabulary — six
            // per-company lists is how one thing acquires three spellings (CLAUDE.md §5).
        ];
    }

    /**
     * A country name not already held by a row — checked against the TABLE, not only against
     * Faker's own `unique()`.
     *
     * ⚠ FAKER'S UNIQUENESS IS PER GENERATOR AND KNOWS NOTHING ABOUT THE DATABASE. Left at
     * `fake()->unique()->country()`, a test that seeds the starting set and then calls this
     * factory can draw `Malaysia` a second time and violate the unique index. It would fail
     * only when the generator happened to reach one of the ten seeded names — **an
     * intermittent failure in a fixture, which is the most expensive kind to diagnose**, since
     * the test that breaks is rarely the test that is wrong.
     *
     * Checking the table was chosen over a suffix that cannot collide (`Malaysia (xyz)`)
     * because the suffix would put values in the column that no picker, report or seeder would
     * ever produce — a fixture that is safe by being unrealistic. This stays realistic and is
     * safe by knowing what is there.
     *
     * `withTrashed()`, because the unique index covers withdrawn rows: a soft-deleted `Nepal`
     * still holds the name, which is what makes restore-don't-recreate work.
     */
    private function unusedCountryName(): string
    {
        $taken = Nationality::withTrashed()->pluck('name')->all();

        // Faker's list runs to a couple of hundred countries, so an unused one is found on the
        // first attempt or two. The bound is here so a suite that has genuinely exhausted the
        // list fails with the message below rather than as a constraint violation raised three
        // layers away from its cause.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $name = fake()->unique()->country();

            if (! in_array($name, $taken, true)) {
                return $name;
            }
        }

        throw new RuntimeException(
            'NationalityFactory could not draw a country name that is not already in '
            .'`nationalities` after 50 attempts. Name the row explicitly with ->named() if the '
            .'test needs a specific one; a fixture cannot invent a country that does not exist.'
        );
    }

    /** One named country — the starting set, or anything a test needs by name. */
    public function named(string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $name,
        ]);
    }

    /**
     * A withdrawn nationality.
     *
     * ⚠ Deactivation IS the soft delete — there is no `is_active` column, and this state exists
     * partly so nobody reintroduces one looking for a way to express it. Employees carrying it
     * keep a valid `nationality_id`; restore() brings it back into the picker.
     */
    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
