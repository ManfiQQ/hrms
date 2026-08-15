<?php

namespace Database\Seeders;

use App\Models\Nationality;
use App\Models\User;
use App\Services\Audit\AuthorshipContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The nationality starting set, ten values — `adr/0013` decision 6.
 *
 * ⚠ A STARTING SET, NOT A CLOSED LIST. `nationalities` is a reference table rather than an enum
 * because the list grows every time the group hires from somewhere new. **HR** adds to it from
 * the UI — not Master Admin, which is where this deliberately differs from `JobFunctionSeeder`
 * (adr/0013 decision 6) — and a new nationality needs neither a migration nor a seeder edit.
 *
 * Idempotent, and by the same route as `JobFunctionSeeder`: match on `name` withTrashed(), skip
 * what exists. Running `db:seed` twice does not produce a second `Nepal`, and it does not
 * resurrect a nationality somebody has deliberately withdrawn.
 */
class NationalitySeeder extends Seeder
{
    public function run(): void
    {
        // ⚠ adr/0009 decision 2 — a seeder has no authenticated session, so it NAMES the
        // account it acts as. The shortcut is for "no session", never for "no accountable
        // actor", and the installing Master Admin genuinely is who this runs as.
        app(AuthorshipContext::class)->run(
            $this->installer(),
            'Seeding the nationality starting set at installation (adr/0013 decision 6).',
            fn () => $this->seedStartingSet()
        );
    }

    /**
     * The account the installation acts as.
     *
     * ⚠ DatabaseSeeder calls MasterAdminSeeder first, so this is present in the ordinary run.
     * It throws rather than inventing one, because an invented actor is the confident falsehood
     * `adr/0009` decision 3 rejects for backfilling.
     */
    private function installer(): User
    {
        $installer = User::query()->where('system_access', 'FULL')->orderBy('id')->first();

        if ($installer === null) {
            throw new RuntimeException(
                'NationalitySeeder needs a Master Admin to attribute its rows to '
                .'(adr/0009 decision 2). Run MasterAdminSeeder first — DatabaseSeeder already '
                .'orders it that way.'
            );
        }

        return $installer;
    }

    private function seedStartingSet(): void
    {
        foreach (Nationality::STARTING_SET as $name) {
            // ⚠ withTrashed(), so a withdrawn nationality is matched rather than duplicated.
            // Without it the unique index on `name` would reject the insert outright — and if
            // it did not, re-seeding would quietly undo a decision to withdraw one.
            // Deactivation IS the soft delete, so a soft-deleted row is a decision, not debris.
            $existing = Nationality::withTrashed()->firstWhere('name', $name);

            if ($existing !== null) {
                continue;
            }

            Nationality::create(['name' => $name]);
        }
    }
}
