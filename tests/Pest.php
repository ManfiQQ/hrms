<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
 * ⚠ Integration tests run WITHOUT a wrapping transaction, and that is the whole reason the
 * suite exists.
 *
 * RefreshDatabase wraps each test in a transaction and rolls it back afterwards, so
 * DB::transactionLevel() is already 1 before the test body runs. Anything whose behaviour is
 * defined against transaction level — AuditLogger, whose batch_id is bound to the
 * transaction and released when it reaches level 0 (audit-trail.spec.md BR-AT12) — cannot be
 * observed truthfully under it: an inner DB::transaction() would be a savepoint, the level
 * would never reach 0, and the batch would never be released. The test would pass while the
 * production semantics went unexercised.
 *
 * DatabaseTruncation empties the tables between tests instead, leaving transaction handling
 * to the code under test. It is slower, so put a test here only when it genuinely needs real
 * transaction boundaries or DDL.
 */
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Integration');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
