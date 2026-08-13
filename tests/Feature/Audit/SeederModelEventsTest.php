<?php

use App\Models\JobFunction;
use Database\Seeders\DatabaseSeeder;
use Symfony\Component\Finder\Finder;

/**
 * Guards the one known path by which every model hook in this system gets switched off at
 * once — `conventions.md` §9, "A model hook is enforcement only where events are enabled".
 *
 * Laravel's `WithoutModelEvents` trait was scaffolding on `DatabaseSeeder`, chosen by nobody,
 * and it suppressed all model events for the whole seeding run. Verified on 2026-08-13:
 * inside `Model::withoutEvents()`, `audit_logs` and `security_events` both accepted an UPDATE
 * and a DELETE that are refused everywhere else. Append-only, write-once `file_path`, BR-16
 * restricted grants and `AuthorshipObserver` were all bypassed with it.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════
 * ⚠ WHAT THIS GUARD DOES NOT CATCH — READ BEFORE TRUSTING IT
 * ═══════════════════════════════════════════════════════════════════════════════════════
 *
 * **It guards ONE KNOWN PATH, not the class of problem.** Specifically it does NOT catch:
 *
 *   - `Model::withoutEvents()` called anywhere in `app/` — a service, an Action, a console
 *     command, a queue job. Nothing here looks outside `database/seeders/`.
 *   - A future seeder suppressing events some other way: `Model::unsetEventDispatcher()`,
 *     `Event::fake()`, `withoutEvents()` on an individual model, or a helper that wraps any
 *     of them under a different name.
 *   - Third-party or framework code doing the same thing inside a package.
 *
 * **Do not read a green run here as "events are guaranteed on".** It means these seeders do
 * not take the one route we already know about. A guard that looks stronger than it is, is
 * worse than a weaker one honestly labelled — it stops people looking for the check that is
 * actually missing (`conventions.md` §9).
 *
 * The durable protection is fail-closed constraints, not this test. `created_by` being
 * NOT NULL is what turned a silent NULL into a failed seeder in a single run, after the
 * bypass had gone unnoticed since the first seeder ever written.
 */

/**
 * ⚠ COMMENTS ARE STRIPPED BEFORE SEARCHING, and that is not a nicety — the docblock above
 * says `WithoutModelEvents` several times, and `DatabaseSeeder` carries a long comment
 * explaining why the trait was removed. A naive text search would go red on its own
 * documentation, which is `conventions.md` §9's third recorded example of a guard defeated by
 * the prose describing the rule it enforces.
 *
 * This test passing WHILE those comments exist is the evidence that the stripping works.
 */
function seederCodeWithoutComments(string $path): string
{
    $code = '';

    foreach (token_get_all(file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

it('finds the seeders it is meant to be guarding', function () {
    // conventions.md §9: a guard over a collection must assert the collection is not empty.
    $files = iterator_to_array(Finder::create()->files()->in(database_path('seeders'))->name('*.php'));

    expect($files)->not->toBeEmpty();
});

/**
 * PART 1 — the known route, by name, in code rather than in prose.
 */
it('has no seeder suppressing model events for the whole run', function () {
    $offenders = [];

    foreach (Finder::create()->files()->in(database_path('seeders'))->name('*.php') as $file) {
        $code = seederCodeWithoutComments($file->getRealPath());

        if (preg_match('/\bWithoutModelEvents\b|\bwithoutEvents\s*\(/', $code) === 1) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([], sprintf(
        "These seeders suppress model events: %s.\n".
        'That switches off append-only enforcement on audit_logs and security_events, the '.
        'write-once lock on employee_documents.file_path, BR-16 restricted grants and '.
        'AuthorshipObserver — all of it, silently, for the whole run (conventions.md §9). '.
        'If one seeder genuinely needs events suppressed, scope it to that seeder and say why.',
        implode(', ', $offenders)
    ));
});

/**
 * PART 2 — the effect, not the spelling.
 *
 * ⚠ This is the half that survives a rename. Part 1 knows one string; this knows what the
 * string was preventing. A seeded row carrying an author is only possible if the observer
 * ran, which is only possible if model events were enabled.
 */
it('produces authored rows when the full seeder runs', function () {
    $this->seed(DatabaseSeeder::class);

    $seeded = JobFunction::withTrashed()->get();

    expect($seeded)->not->toBeEmpty();

    foreach ($seeded as $function) {
        expect($function->created_by)->not->toBeNull()
            ->and($function->updated_by)->not->toBeNull();
    }
});
