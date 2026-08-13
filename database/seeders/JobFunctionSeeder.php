<?php

namespace Database\Seeders;

use App\Models\JobFunction;
use App\Models\User;
use App\Services\Audit\AuthorshipContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The job-function starting set — BR-15, `adr/0003` decision 2.
 *
 * ⚠ A STARTING SET, NOT A CLOSED LIST. `job_functions` is a reference table rather than an
 * enum precisely because this list grows as the remaining workplaces — factory, studio,
 * galleria, restaurant — are mapped. Master Admin adds to it from the UI; a new function does
 * not need a migration, and it must not need a seeder edit either.
 *
 * Idempotent: `updateOrCreate` on `name`, which carries a unique index. Running `db:seed`
 * twice does not produce a second `Media`, and it does not resurrect a function Master Admin
 * has deliberately deactivated — see the note on soft deletes below.
 *
 * ⚠ TWO THINGS ARE DELIBERATELY ABSENT AND MUST NOT BE ADDED (BR-15):
 *
 *   Intern    is an `employment_type`, not a job function.
 *   Logistic  is a BRANCH (adr/0002), not a job function.
 *
 * An intern doing media work has job function `Media` and `employment_type = INTERN` — two
 * facts, two fields. Seeding either here would create a second way to express something the
 * schema already expresses once, and the two would eventually disagree.
 */
class JobFunctionSeeder extends Seeder
{
    public function run(): void
    {
        // ⚠ adr/0009 decision 2 — a seeder has no authenticated session, so it NAMES the
        // account it acts as. The shortcut is for "no session", never for "no accountable
        // actor", and the installing Master Admin genuinely is who this runs as.
        app(AuthorshipContext::class)->run(
            $this->installer(),
            'Seeding the job function starting set at installation (BR-15).',
            fn () => $this->seedStartingSet()
        );
    }

    /**
     * The account the installation acts as.
     *
     * ⚠ DatabaseSeeder calls MasterAdminSeeder first, so this is present in the ordinary run.
     * It throws rather than inventing one, because an invented actor is the confident
     * falsehood `adr/0009` decision 3 rejects for backfilling.
     */
    private function installer(): User
    {
        $installer = User::query()->where('system_access', 'FULL')->orderBy('id')->first();

        if ($installer === null) {
            throw new RuntimeException(
                'JobFunctionSeeder needs a Master Admin to attribute its rows to '
                .'(adr/0009 decision 2). Run MasterAdminSeeder first — DatabaseSeeder already '
                .'orders it that way.'
            );
        }

        return $installer;
    }

    private function seedStartingSet(): void
    {
        foreach (JobFunction::STARTING_SET as $name) {
            // ⚠ withTrashed(), so a deactivated function is matched rather than duplicated.
            // Without it the unique index on `name` would reject the insert outright — and if
            // it did not, re-seeding would quietly undo a Master Admin's decision to withdraw
            // a function. Deactivation IS the soft delete (schema.md), so a soft-deleted row
            // is a decision, not debris.
            $existing = JobFunction::withTrashed()->firstWhere('name', $name);

            if ($existing !== null) {
                continue;
            }

            JobFunction::create(['name' => $name]);
        }
    }
}
