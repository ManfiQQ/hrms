<?php

use App\Models\User;
use Database\Seeders\MasterAdminSeeder;

/**
 * The seeder's credentials come from configuration, and there is no fallback for any of them
 * — auth-rbac.spec.md §5.8, adr/0001 decision 5.
 *
 * ⚠ WHY THIS FILE EXISTS EVEN THOUGH THE RULE ALREADY HOLDS.
 *
 * Until now the rule was asserted for ONE key out of three: a missing phone number was
 * covered, a missing email and a missing password were not. A rule tested on one of its three
 * inputs is a rule that can be half-removed without the suite noticing — and the input most
 * likely to acquire a convenient default is the password, because that is the one that makes
 * `migrate:fresh --seed` fail on a fresh clone.
 *
 * ⚠ THE FAILURE MODE BEING GUARDED AGAINST IS NOT A CRASH — IT IS A SUCCESS.
 *
 * A seeder that fell back to a literal would create the most powerful account in the system
 * with a credential anybody can read in the repository, print "Master Admin created", and
 * exit zero. Nothing would look wrong. That is the same shape as the defect adr/0006 closed:
 * an install that appears to have worked and has not.
 */
beforeEach(function () {
    config()->set('auth.master_admin', [
        'email' => 'master@example.test',
        'phone_no' => '0193034601',
        'password' => 'installer-secret',
    ]);
});

it('refuses to seed when a credential is missing, and creates nothing', function (string $key) {
    config()->set("auth.master_admin.{$key}", null);

    expect(fn () => $this->seed(MasterAdminSeeder::class))->toThrow(RuntimeException::class);

    // Both halves. A seeder that threw AFTER writing would have created the account it was
    // refusing to create, and the throw would read as a failed install that had in fact
    // succeeded halfway.
    expect(User::query()->count())->toBe(0);
})->with(['email', 'phone_no', 'password']);

it('refuses when a credential is present but empty', function (string $key) {
    // An unset variable and `MASTER_ADMIN_PASSWORD=` in a .env are the same intent and must
    // produce the same refusal. blank() covers both; a null check alone would not.
    config()->set("auth.master_admin.{$key}", '');

    expect(fn () => $this->seed(MasterAdminSeeder::class))->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);
})->with(['email', 'phone_no', 'password']);

it('refuses when nothing is configured at all — a fresh clone with no .env values', function () {
    config()->set('auth.master_admin', ['email' => null, 'phone_no' => null, 'password' => null]);

    expect(fn () => $this->seed(MasterAdminSeeder::class))->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);
});

it('names all three variables in the failure, so the fix is obvious from the message', function () {
    // This seeder is the single first way into the system. An error that says only "missing
    // configuration" leaves someone guessing at the one thing standing between them and a
    // working install.
    config()->set('auth.master_admin.password', null);

    try {
        $this->seed(MasterAdminSeeder::class);
        $this->fail('the seeder must refuse to run');
    } catch (RuntimeException $e) {
        expect($e->getMessage())
            ->toContain('MASTER_ADMIN_EMAIL')
            ->toContain('MASTER_ADMIN_PHONE')
            ->toContain('MASTER_ADMIN_PASSWORD')
            ->toContain('.env.example');
    }
});

/**
 * ⚠ The structural guard: a default cannot be introduced without this failing.
 *
 * Every test above passes just as well against `env('MASTER_ADMIN_PASSWORD', 'password')` —
 * because with a default the value is never blank, so the refusal never fires and the tests
 * never reach it. Behaviour alone cannot catch a fallback; only the declaration can.
 */
it('declares the credential keys with no fallback value', function () {
    // ⚠ Comments stripped first, and this is not fussiness — the first version of this test
    // was VACUOUS because of it. config/auth.php's own docblock contains the string
    // env('MASTER_ADMIN_PASSWORD') while explaining why env() is wrong, so the regex matched
    // the prose and passed happily against a real fallback on the line below it. Verified by
    // introducing one and watching the test not care.
    $code = collect(file(config_path('auth.php')))
        ->reject(fn (string $line) => preg_match('~^\s*(//|\*|/\*|\|)~', trim($line)) === 1
            || str_starts_with(trim($line), '|'))
        ->implode('');

    foreach (['MASTER_ADMIN_EMAIL', 'MASTER_ADMIN_PHONE', 'MASTER_ADMIN_PASSWORD'] as $variable) {
        expect($code)->toMatch("/env\\('{$variable}'\\)/",
            "config/auth.php must read {$variable} with NO default argument. A fallback would ".
            'create the most powerful account in the system with a credential anybody can read '.
            'in the repository, print "Master Admin created", and exit zero — a failed install '.
            'that looks like a successful one (adr/0001 decision 5, spec §5.8).'
        );
    }
});

it('keeps every credential out of the seeder file itself', function () {
    // .env is gitignored; seeder files are not. A password written here would stay in git
    // history permanently even after being edited out (adr/0001 decision 5).
    $seeder = file_get_contents(database_path('seeders/MasterAdminSeeder.php'));

    expect($seeder)->toContain("config('auth.master_admin.password')")
        // No quoted string containing an @ — i.e. no literal email address.
        ->and($seeder)->not->toMatch("/'[^']*@[^']*'/");
});

it('reads configuration and never env() directly, in code rather than in comments', function () {
    // ⚠ Comments stripped first. This file's own docblock explains why env() is wrong, so a
    // naive search for "env(" matches the warning against it — a check that would fail for
    // the presence of the reasoning it exists to enforce.
    $code = collect(file(database_path('seeders/MasterAdminSeeder.php')))
        ->reject(fn (string $line) => preg_match('~^\s*(//|\*|/\*)~', $line) === 1)
        ->implode('');

    expect($code)->not->toContain('env(',
        'MasterAdminSeeder must read config(), never env() directly. After config:cache — '.
        'which production runs — env() returns null, and this seeder would abort on a fresh '.
        'install pointing at a variable that is demonstrably present in .env (spec §5.8).'
    );
});

it('lists all three variables in .env.example, with no values', function () {
    $example = file_get_contents(base_path('.env.example'));

    foreach (['MASTER_ADMIN_EMAIL=', 'MASTER_ADMIN_PHONE=', 'MASTER_ADMIN_PASSWORD='] as $line) {
        expect($example)->toContain($line);
    }

    // ⚠ Blank, not filled in. A committed example value is a default by another name — and
    // the one people copy without reading.
    expect($example)->not->toMatch('/MASTER_ADMIN_(EMAIL|PHONE|PASSWORD)=\S/');
});
