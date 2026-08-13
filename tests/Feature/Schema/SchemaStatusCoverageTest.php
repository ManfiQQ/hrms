<?php

use Symfony\Component\Finder\Finder;

/**
 * `CLAUDE.md` Principle #5 — *`schema.md` is updated in the same commit as any migration* —
 * given something that carries it.
 *
 * Until 2026-08-13 the most-cited principle in this project was enforced by nothing. It broke
 * in PR #37: the authorship migration landed and `schema.md` never mentioned it. **It passed
 * the `conventions.md` §10 checklist, because §10 does not check it**, and no test compared the
 * two. The break was found by a human reading the file for another reason.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════
 * ⚠ WHAT THIS GUARD DOES NOT CHECK — READ BEFORE TRUSTING IT
 * ═══════════════════════════════════════════════════════════════════════════════════════
 *
 * **It checks EXISTENCE, not CORRECTNESS.** A migration listed in the Status table can be
 * described completely wrongly — wrong columns, wrong types, wrong nullability, wrong indexes,
 * or the per-table section can contradict the migration outright — and this guard stays green.
 * It knows only that a filename appears on both sides.
 *
 * **A green run here does not mean `schema.md` is accurate.** It means no migration is
 * undocumented and no documented migration is missing. Those are different claims, and this
 * one is much the weaker.
 *
 * Column-level verification — comparing a migration's actual columns against its per-table
 * section — is a **separate ADR and a separate guard**. It is deliberately not attempted here,
 * and not attempted by halves: a guard that checked some columns would be the worst of the
 * three options, because it would look like the strong version.
 *
 * ⚠ Written on the guard rather than in a commit message on purpose. Without it this test
 * closes the anxiety without closing the gap, which is more dangerous than no guard at all —
 * it stops people looking for the check that is still missing (`conventions.md` §9).
 */

/**
 * ⚠ TABLE ROWS ONLY, NEVER "ANY .php IN THE SECTION".
 *
 * A filename mentioned in PROSE would otherwise count as documented, and the guard would pass
 * on somebody's explanatory blockquote rather than on the record. `schema.md` genuinely does
 * name migration files inside prose elsewhere, so this is a live risk rather than a
 * theoretical one — and it is `conventions.md` §9's third recorded example, where a guard was
 * defeated by the documentation of the very rule it enforced.
 *
 * @return list<string>
 */
function schemaStatusMigrations(): array
{
    $document = file_get_contents(base_path('docs/schema.md'));

    // The Status section only: from its heading to the next top-level heading.
    $start = strpos($document, '## Status');
    $end = strpos($document, "\n## ", $start + 1);
    $section = substr($document, $start, $end === false ? null : $end - $start);

    $listed = [];

    foreach (explode("\n", $section) as $line) {
        if (! str_starts_with(trim($line), '|')) {
            continue;
        }

        preg_match_all('/([0-9a-z_]+\.php)/', $line, $matches);

        foreach ($matches[1] as $file) {
            $listed[] = $file;
        }
    }

    return array_values(array_unique($listed));
}

/** @return list<string> */
function migrationFilesOnDisk(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(database_path('migrations'))->name('*.php') as $file) {
        $files[] = $file->getFilename();
    }

    sort($files);

    return $files;
}

it('finds both sides of the comparison', function () {
    // conventions.md §9: a guard over a collection must assert the collection is not empty, or
    // it passes forever while comparing nothing with nothing.
    expect(migrationFilesOnDisk())->not->toBeEmpty()
        ->and(schemaStatusMigrations())->not->toBeEmpty();
});

/**
 * ⚠ DIRECTION (i) — the failure that actually happened.
 *
 * A migration is written, merged, and `schema.md` never hears about it. Nothing else in the
 * project notices: the suite passes, §10 passes, and the schema document quietly stops being
 * the record it claims to be.
 *
 * ⚠ NO EXEMPTION MECHANISM EXISTS HERE, DELIBERATELY. Not a skip list, not a prefix filter,
 * not an "ignore framework migrations" flag. Laravel's own `cache` and `jobs` migrations are
 * listed in the Status table like everything else, because the table is an inventory of what
 * is migrated and both are migrated. A door built before anybody knocks is a door the second
 * caller walks through. If a genuine exception ever appears, it gets an ADR.
 */
it('lists every migration in schema.md\'s Status table', function () {
    $undocumented = array_values(array_diff(migrationFilesOnDisk(), schemaStatusMigrations()));

    expect($undocumented)->toBe([], sprintf(
        "These migrations exist but are not listed in schema.md's Status table: %s.\n".
        'CLAUDE.md Principle #5 requires schema.md to be updated in the SAME COMMIT as any '.
        'migration. Add a row; there is no skip list, and adding one needs an ADR.',
        implode(', ', $undocumented)
    ));
});

/**
 * ⚠ DIRECTION (ii) — and it is not symmetry for its own sake.
 *
 * It catches the RENAME. Renaming a migration file without touching the table produces TWO
 * failures at once: the new name is undocumented (direction i) and the old name has no file
 * (this one). **That pairing is what tells the reader it was one act, not two unrelated
 * mistakes** — and it points at the fix, which is editing one row rather than adding one and
 * hunting for the other.
 *
 * It also catches a deletion, and a typo in the table, both of which leave the document
 * describing something that is not there.
 */
it('has a file for every migration named in the Status table', function () {
    $missing = array_values(array_diff(schemaStatusMigrations(), migrationFilesOnDisk()));

    expect($missing)->toBe([], sprintf(
        "schema.md's Status table names these migrations, but no such file exists: %s.\n".
        'If a migration was renamed you should be seeing TWO failures — this one and the '.
        'undocumented-migration one — which together say it was a rename rather than two '.
        'separate faults.',
        implode(', ', $missing)
    ));
});
