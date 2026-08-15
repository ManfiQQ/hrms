<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * A parent company by default — parent_company_id is null.
     *
     * The default is the parent rather than a subsidiary because read scope derives from
     * this column, and a test that forgets to set it should get the *broader* case openly
     * rather than a subsidiary that quietly looks correct (adr/0004 decision 1).
     */
    public function definition(): array
    {
        return [
            // ⚠ NOT CHECKED AGAINST THE TABLE, AND THAT IS MEASURED RATHER THAN ASSUMED.
            // `fake()->company()` builds from Western surnames plus a suffix — EBERT-DIBBERT,
            // BAYER, KUNDE AND GISLASON — so it cannot reach the six canonical names in
            // CLAUDE.md §5 or any variant spelling of them. 200,000 draws were searched for
            // HADDAD, SOFEEYA, ESSOFEEYA, ZISH, TURSENIA, SLEGHO, THALHAH, SDN BHD and PLT:
            // zero hits. A table check here would cost a query per fixture for a collision
            // that cannot occur, which is why `code` below has one and this does not.
            //
            // ⚠ THE MEASUREMENT IS OF TODAY'S FAKER. It holds only while the generator keeps
            // building names this way; a provider that started emitting other formats would
            // retire this reasoning, and the number above is here so the next reader can
            // re-run it rather than trust it.
            'name' => strtoupper(fake()->unique()->company()),

            'code' => $this->unusedCode(),

            'parent_company_id' => null,
            'status' => 'ACTIVE',
        ];
    }

    /**
     * A three-letter code no row already holds — checked against the TABLE, not only against
     * Faker's own `unique()`.
     *
     * ⚠ FAKER'S UNIQUENESS IS PER GENERATOR AND BLIND TO ROWS THAT ARRIVED BY SEEDER. Two of
     * the six seeded codes are three letters and therefore reachable by `lexify('???')`:
     * **`AHS` and `AIM`**. The other four are longer and can never be drawn.
     *
     * ⚠ MEASURED, BECAUSE THE ODDS ARE WHAT JUSTIFIES THE QUERY. One draw in 7,692 hits a
     * seeded code (200,000 draws, 26 hits; 2/17,576 in theory), and a full suite run takes
     * **843 draws while `AHS` or `AIM` already exists** — about a **9% chance of one red run
     * in every run**. It surfaces as `1062 Duplicate entry 'AHS'` inside whichever test
     * happened to draw, in a file that has nothing to do with companies, and it reads like a
     * seeder bug.
     *
     * ⚠ WIDENING THE SPACE WAS REJECTED. `lexify('????')` would cut it to roughly one run in
     * 300, which is worse rather than better: a flake nobody can reproduce is a flake nobody
     * fixes. `withTrashed()`, because the unique index covers soft-deleted rows, and the check
     * survives `adr/0003` decision 9 — Master Admin may add a seventh company with any code,
     * and nothing structural can anticipate which.
     */
    private function unusedCode(): string
    {
        $taken = Company::withTrashed()->pluck('code')->all();

        // 26^3 codes against a handful taken, so an unused one arrives on the first attempt
        // essentially always. The bound exists so an exhausted space fails HERE, naming its
        // cause, rather than as a constraint violation three layers away from it.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = strtoupper(fake()->unique()->lexify('???'));

            if (! in_array($code, $taken, true)) {
                return $code;
            }
        }

        throw new RuntimeException(
            'CompanyFactory could not draw a three-letter code that is not already in '
            .'`companies` after 50 attempts. Name the code explicitly if the test needs a '
            .'specific one.'
        );
    }

    /**
     * A subsidiary under the given parent, or under a newly created one.
     *
     * This is the distinction read scope turns on: an employee of the parent reads the
     * whole group, an employee of a subsidiary reads that subsidiary only.
     */
    public function subsidiary(?Company $parent = null): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_company_id' => $parent?->id ?? Company::factory(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'INACTIVE',
        ]);
    }
}
