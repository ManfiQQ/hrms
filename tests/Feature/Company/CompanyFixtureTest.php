<?php

use App\Models\Company;
use Database\Seeders\CompanySeeder;

/**
 * What `CompanyFactory` must guarantee against the six seeded entities (`CLAUDE.md` §5).
 *
 * ⚠ `companies` CARRIES TWO UNIQUE COLUMNS AND ONLY ONE OF THEM IS AT RISK. `code` is drawn
 * from 26³ three-letter combinations, and two of the six seeded codes — `AHS` and `AIM` — sit
 * inside that space. `name` is drawn from `fake()->company()`, which builds from Western
 * surnames and cannot reach the canonical six; that half was measured and left unguarded, with
 * the numbers written on the factory itself.
 */

/**
 * The RNG seed at which `CompanyFactory`'s own draw order — name first, then code — reaches
 * `AHS`. Found by search, hard-coded so the test is deterministic rather than lucky.
 */
const COMPANY_CODE_COLLISION_SEED = 5577;

/**
 * ⚠ DETERMINISTIC, BECAUSE A FLAKY GUARD FOR A FLAKY BUG IS WORSE THAN NO GUARD. Creating many
 * companies and hoping for a clash would reproduce the defect about 9% of the time — the same
 * odds that let it sit unnoticed in the first place.
 *
 * The premise is ASSERTED, not assumed: a seeded RNG maps to values through PHP's `mt_rand` and
 * Faker's providers, so a change in either could quietly stop this seed reaching `AHS`. The
 * test would then pass while exercising nothing, which is the failure `conventions.md` §9 calls
 * a guard pointed at an empty set. If that first expectation fails, re-run the search rather
 * than deleting the test.
 */
it('never draws a company code the table already holds', function () {
    $this->seed(CompanySeeder::class);

    expect(Company::count())->toBe(6);

    fake()->seed(COMPANY_CODE_COLLISION_SEED);
    fake()->unique(true);
    fake()->unique()->company();

    expect(strtoupper(fake()->unique()->lexify('???')))
        ->toBe('AHS', 'this seed must reach AHS, or the test below proves nothing');

    // Rewind to the same point and clear the generator's memory of those draws, so the
    // factory's own first attempt is the colliding one.
    fake()->seed(COMPANY_CODE_COLLISION_SEED);
    fake()->unique(true);

    $company = Company::factory()->create();

    expect($company->code)->not->toBe('AHS')
        ->and(Company::query()->where('code', 'AHS')->count())->toBe(1)
        ->and(Company::query()->where('code', 'AHS')->value('name'))->toBe('AL HADDAD SUCCESS SDN BHD');

    // Leave the generator random again for whatever runs next in this process.
    fake()->seed();
    fake()->unique(true);
});
