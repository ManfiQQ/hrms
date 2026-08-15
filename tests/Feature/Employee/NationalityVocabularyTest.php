<?php

use App\Models\Employee;
use App\Models\Nationality;
use App\Models\User;
use Database\Seeders\NationalitySeeder;
use Illuminate\Database\QueryException;

/**
 * The `nationalities` vocabulary — `adr/0013` decision 6.
 *
 * ⚠ THIS TABLE HAS A WEAKER GUARANTEE THAN `job_functions` ON PURPOSE, and these tests cover
 * exactly what is left. HR may create entries here, where only Master Admin may extend the job
 * functions, so the structural defence against two spellings of one country is the unique index
 * and nothing else. It stops `Bangladesh` twice; it does not stop `Myanmar` and `Burma`
 * coexisting, and no test here pretends otherwise.
 */
beforeEach(function () {
    // ⚠ A real actor, because AuthorshipObserver refuses a write with none (adr/0009), and
    // `NationalitySeeder` additionally needs a Master Admin to name as the installer.
    $this->actingAs(User::factory()->masterAdmin()->create());
});

it('refuses a second nationality with the same name', function () {
    Nationality::factory()->named('Bangladesh')->create();

    expect(fn () => Nationality::factory()->named('Bangladesh')->create())
        ->toThrow(QueryException::class);
});

/**
 * ⚠ THE NAME STAYS RESERVED WHILE WITHDRAWN, AND THAT IS WHAT MAKES RESTORE THE ONLY WAY BACK.
 *
 * If a withdrawn `Myanmar` could be recreated, the new row would be a second `Myanmar` as far
 * as history is concerned: the employees hired under the first still point at it, and the
 * vocabulary would hold one name meaning two things. Restoring carries the original row back
 * with everything already attached to it.
 */
it('keeps a withdrawn nationality name reserved, so the way back is restore', function () {
    $myanmar = Nationality::factory()->named('Myanmar')->create();

    $myanmar->delete();

    expect(Nationality::query()->where('name', 'Myanmar')->exists())->toBeFalse()
        ->and(fn () => Nationality::factory()->named('Myanmar')->create())
        ->toThrow(QueryException::class);

    $myanmar->restore();

    expect(Nationality::query()->where('name', 'Myanmar')->exists())->toBeTrue();
});

/**
 * ⚠ THE SECOND RUN MUST NOT RESURRECT A WITHDRAWAL. Re-seeding is something an installation
 * does routinely; a seeder that recreated a nationality somebody had deliberately withdrawn
 * would undo a decision without saying so, and the row would come back with a new id while the
 * old one stayed soft-deleted beneath it.
 */
it('seeds the starting set idempotently and never resurrects a withdrawn entry', function () {
    $this->seed(NationalitySeeder::class);

    expect(Nationality::count())->toBe(10)
        ->and(Nationality::query()->pluck('name')->all())
        ->toEqualCanonicalizing(Nationality::STARTING_SET);

    Nationality::firstWhere('name', 'Nepal')->delete();

    $this->seed(NationalitySeeder::class);

    expect(Nationality::count())->toBe(9)
        ->and(Nationality::withTrashed()->count())->toBe(10)
        ->and(Nationality::withTrashed()->firstWhere('name', 'Nepal')->trashed())->toBeTrue();
});

/**
 * ⚠ WITHDRAWING A NATIONALITY MUST STOP IT BEING CHOSEN, NEVER STOP IT BEING DISPLAYED.
 *
 * `Employee::nationality()` reads `withTrashed()` for this reason alone. Without it the
 * relationship returns null for an employee hired under a nationality since withdrawn — the FK
 * is still valid, `nationality_id` is still NOT NULL, and the Personal tab renders a blank
 * where a country belongs. Nothing errors, which is why this needs a test rather than a
 * comment.
 */
it('shows a withdrawn nationality on the record that already holds it', function () {
    $myanmar = Nationality::factory()->named('Myanmar')->create();
    $employee = Employee::factory()->ofNationality($myanmar)->create();

    $myanmar->delete();

    expect(Nationality::query()->pluck('name')->all())->not->toContain('Myanmar')
        ->and($employee->fresh()->nationality?->name)->toBe('Myanmar');
});
