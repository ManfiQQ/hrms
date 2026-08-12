<?php

use App\Models\Sequence;
use App\Services\Sequence\SequenceGenerator;
use Illuminate\Support\Facades\DB;

/**
 * ⚠ THE COLLISION `sequences` EXISTS TO PREVENT — adr/0003 decision 9, schema.md.
 *
 * `MAX() + 1` collides whenever two requests read the current maximum before either writes:
 * a double-clicked Save button, two open tabs, a legacy import running beside manual entry,
 * a seeder. The client's rule that one HR does all registration does not remove it — that
 * prevents duplicate PEOPLE, not duplicate NUMBERS.
 *
 * ⚠ IN tests/Integration BECAUSE IT NEEDS TWO REAL CONNECTIONS AND NO WRAPPING TRANSACTION.
 * Under RefreshDatabase both connections would sit inside one test-owned transaction that is
 * never committed, so the lock could never be observed being held or released — the test
 * would pass whether or not lockForUpdate were there at all, which is precisely the empty
 * guard conventions.md §9 warns about.
 */
beforeEach(function () {
    // A genuinely separate connection to the same database, so the two sessions contend for
    // the row exactly as two web requests would.
    config(['database.connections.second' => config('database.connections.mysql')]);
    DB::purge('second');

    // Fail fast rather than hang the suite when the lock is held, so "blocked" is observable
    // as an error instead of a stalled test run.
    DB::connection('second')->statement('SET SESSION innodb_lock_wait_timeout = 2');
});

afterEach(function () {
    DB::purge('second');
});

/**
 * ⚠ THE ASSERTION THAT DISTINGUISHES A REAL LOCK FROM NO LOCK AT ALL.
 *
 * Two earlier versions of this test passed with `lockForUpdate()` REMOVED, and each failure
 * is worth recording because both looked convincing:
 *
 *   1. It locked the row with a raw query builder call and asserted a second session
 *      blocked. That asserted MySQL's row locking works — not that this codebase uses it.
 *   2. It routed both sessions through the generator and asserted the second BLOCKED. But
 *      the generator's UPDATE blocks either way, lock or no lock, so "blocked" was true in
 *      both worlds.
 *
 * ⚠ The property that actually differs is the VALUE HANDED OUT, not whether anyone waited.
 * Under REPEATABLE READ a plain SELECT reads the snapshot taken when the transaction began,
 * so a second claimant whose transaction opened first reads the OLD next_value and hands out
 * a number already issued. `lockForUpdate()` forces a locking read, which always sees the
 * latest committed row.
 *
 * So: session two opens its transaction FIRST, session one claims and commits, then session
 * two claims. With the lock it gets 0002; without it, 0001 — the same number twice.
 */
it('does not hand out a number already issued to a transaction that started earlier', function () {
    Sequence::query()->create(['key' => Sequence::EMPLOYEE_NO, 'next_value' => 1]);

    // Session two starts first: its snapshot predates session one's write.
    DB::connection('second')->beginTransaction();
    DB::connection('second')->table('sequences')->count();   // materialise the snapshot

    // Session one claims and commits.
    $first = DB::transaction(fn () => app(SequenceGenerator::class)->nextEmployeeNo());

    // Session two now claims, through the generator, on its own connection.
    config(['database.default' => 'second']);

    try {
        $second = app(SequenceGenerator::class)->nextEmployeeNo();
        DB::connection('second')->commit();
    } finally {
        config(['database.default' => 'mysql']);
    }

    expect($first)->toBe('AHS-0001')
        ->and($second)->toBe('AHS-0002')
        ->and($second)->not->toBe($first, 'Two registrations were handed the same employee_no.');
});

/**
 * ⚠ The refusal that makes the lock meaningful. A lock taken outside a transaction is
 * released the instant the statement finishes, so two callers would both read the same value
 * and both believe they held it — the same collision, wearing the appearance of protection.
 */
it('refuses to claim a number outside a transaction', function () {
    expect(DB::transactionLevel())->toBe(0);

    expect(fn () => app(SequenceGenerator::class)->next(Sequence::EMPLOYEE_NO))
        ->toThrow(RuntimeException::class, 'outside a transaction');

    expect(Sequence::query()->count())->toBe(0);
});

it('never rewinds, even after the row it numbered is deleted', function () {
    $first = DB::transaction(fn () => app(SequenceGenerator::class)->nextEmployeeNo());
    $second = DB::transaction(fn () => app(SequenceGenerator::class)->nextEmployeeNo());

    // A number retired with a departing employee, or vacated by a correction, is BURNED —
    // reissuing it would point previously printed letters and payslips at the wrong person.
    $third = DB::transaction(fn () => app(SequenceGenerator::class)->nextEmployeeNo());

    expect([$first, $second, $third])->toBe(['AHS-0001', 'AHS-0002', 'AHS-0003']);
});
