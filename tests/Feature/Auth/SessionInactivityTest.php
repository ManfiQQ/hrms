<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PolicyConfiguration;
use App\Models\User;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\DB;

/**
 * §8 test 6 — the session expires after two hours of INACTIVITY, not two hours after login.
 *
 * ⚠ This distinction is the whole rule and it is invisible in configuration: both readings
 * produce `'lifetime' => 120`. Someone working through the day must never be interrupted;
 * what BR-A6 expires is a session left open on a shared terminal at the factory, studio or
 * galleria. An implementation counting from login would look identical on the first request
 * and be wrong on every shift.
 *
 * ⚠ WHY THIS FILE DOES NOT DRIVE EXPIRY THROUGH $this->get().
 *
 * Laravel's test client does not round-trip a session cookie: the SessionManager caches its
 * store in the container and hands the same loaded instance to every request in a test, and
 * the `sessions` row is written once and then left alone — verified, not assumed. Travelling
 * forward and issuing another request therefore proves nothing at all: the store answers
 * from memory and the assertion passes whatever the driver would have done.
 *
 * Rather than write tests that look stronger than they are, both halves of BR-A6 are
 * asserted where the rule actually lives — in the session handler: `last_activity` stamped
 * forward on every save, and an idle row reading as gone. The login request above is real,
 * so the row under test is one the application actually created.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

    foreach ([$this->ahs, $this->aim] as $company) {
        foreach (['auth.password.min_length' => '6', 'auth.throttle.tier_4.attempts' => '12'] as $key => $value) {
            PolicyConfiguration::create([
                'company_id' => $company->id,
                'key' => $key,
                'value' => $value,
                'effective_from' => now()->toDateString(),
            ]);
        }
    }

    $employee = Employee::factory()->forCompany($this->aim)->create(['phone_no' => '0123456789']);

    $this->user = User::factory()->forEmployee($employee)->create([
        'password' => 'secret123',
        'must_change_password' => false,
    ]);
});

function handler(): DatabaseSessionHandler
{
    return new DatabaseSessionHandler(
        DB::connection(),
        config('session.table', 'sessions'),
        (int) config('session.lifetime'),
        app()
    );
}

/**
 * ⚠ THE HALF THAT SEPARATES "INACTIVITY" FROM "ELAPSED TIME SINCE LOGIN".
 *
 * Every request pushes the window forward. If it did not, this row's `last_activity` would
 * still read from login time, and the person would be signed out mid-shift.
 */
it('stamps last_activity forward on every write, so the window follows the work', function () {
    $this->post('/login', ['phone_no' => '0123456789', 'password' => 'secret123']);

    $id = DB::table('sessions')->where('user_id', $this->user->id)->value('id');
    $atLogin = DB::table('sessions')->where('id', $id)->value('last_activity');

    // Each request saves the session, and this is that save. If it stamped anything other
    // than "now", the window would be measured from something other than activity.
    $this->travel(90)->minutes();
    handler()->write($id, DB::table('sessions')->where('id', $id)->value('payload'));

    $afterWork = DB::table('sessions')->where('id', $id)->value('last_activity');

    expect($afterWork - $atLogin)->toBeGreaterThanOrEqual(90 * 60);
});

/**
 * ⚠ THE HALF THAT SEPARATES "INACTIVITY" FROM "ELAPSED TIME SINCE LOGIN".
 *
 * Six hours after signing in, with a save every ninety minutes, the session is still alive.
 * An implementation counting from login would have logged this person out during their third
 * hour of work.
 */
it('keeps a session alive all day while the person keeps working', function () {
    $this->post('/login', ['phone_no' => '0123456789', 'password' => 'secret123']);

    $id = DB::table('sessions')->where('user_id', $this->user->id)->value('id');

    foreach (range(1, 4) as $ignored) {
        $this->travel(90)->minutes();
        handler()->write($id, DB::table('sessions')->where('id', $id)->value('payload'));

        expect(handler()->read($id))->not->toBe('');
    }

    // Six hours after login, and still readable.
    expect(handler()->read($id))->not->toBe('');
});

/** The mechanism itself: a row idle past the window reads as gone. */
it('treats a session idle longer than the window as expired', function () {
    $this->post('/login', ['phone_no' => '0123456789', 'password' => 'secret123']);

    $id = DB::table('sessions')->where('user_id', $this->user->id)->value('id');

    expect(handler()->read($id))->not->toBe('');

    DB::table('sessions')->where('id', $id)->update([
        'last_activity' => now()->subMinutes((int) config('session.lifetime') + 1)->getTimestamp(),
    ]);

    expect(handler()->read($id))->toBe('');
});

it('does not expire a session idle for less than the window', function () {
    // Both halves. A handler that expired everything would pass the test above and log the
    // whole company out.
    $this->post('/login', ['phone_no' => '0123456789', 'password' => 'secret123']);

    $id = DB::table('sessions')->where('user_id', $this->user->id)->value('id');

    DB::table('sessions')->where('id', $id)->update([
        'last_activity' => now()->subMinutes((int) config('session.lifetime') - 5)->getTimestamp(),
    ]);

    expect(handler()->read($id))->not->toBe('');
});

it('records the session against the user so BR-A15 can find it', function () {
    $this->post('/login', ['phone_no' => '0123456789', 'password' => 'secret123']);

    // BR-A15 terminates access with DELETE FROM sessions WHERE user_id = ?. That requires
    // the row to carry the user id, which file sessions could not offer — which is the whole
    // reason BR-A5 chose the database driver.
    $row = DB::table('sessions')->where('user_id', $this->user->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->last_activity)->toBeGreaterThan(now()->subMinute()->getTimestamp());
});

it('leaves nothing readable once the row is deleted', function () {
    $this->post('/login', ['phone_no' => '0123456789', 'password' => 'secret123']);

    $id = DB::table('sessions')->where('user_id', $this->user->id)->value('id');

    DB::table('sessions')->where('user_id', $this->user->id)->delete();

    // The next request in production builds a fresh store, finds nothing, and starts a guest
    // session. Asserted at the handler because that is where "nothing" is decided.
    expect(handler()->read($id))->toBe('');
});
