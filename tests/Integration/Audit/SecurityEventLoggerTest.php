<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Audit\SecurityEventLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * SecurityEventLogger — audit-trail.spec.md §5.2, BR-AT8.
 *
 * In tests/Integration because the failure-path test renames the table away, which is DDL:
 * MySQL commits implicitly on DDL, so under RefreshDatabase it would break the wrapping
 * transaction and leak every row the test created.
 */
beforeEach(function () {
    $this->logger = app(SecurityEventLogger::class);
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
});

it('records an attempt against an account that exists', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create();
    $user = User::factory()->forEmployee($employee)->create();

    $this->logger->record('LOGIN_FAILED', '0123456789', $user);

    $event = SecurityEvent::query()->sole();

    expect($event->event_type)->toBe('LOGIN_FAILED')
        ->and($event->identifier)->toBe('0123456789')
        ->and($event->user_id)->toBe($user->id)
        ->and($event->company_id)->toBe($this->aim->id);
});

/**
 * ⚠ user_id null is the RETENTION DISCRIMINATOR (BR-AT11), not missing data. A logger that
 * helpfully filled it in would silently convert a 90-day row into a permanent one.
 */
it('leaves user_id and company_id null for an attempt against a number in no account', function () {
    $this->logger->record('LOGIN_FAILED', '0111111111');

    $event = SecurityEvent::query()->sole();

    expect($event->user_id)->toBeNull()
        ->and($event->company_id)->toBeNull()
        ->and($event->identifier)->toBe('0111111111')
        ->and($event->isUnattributed())->toBeTrue();
});

it('stores the request origin verbatim, hostile user agents included', function () {
    // §11: user_agent is an attacker-controlled string. It is stored unparsed and
    // unvalidated precisely because it is a hint, never proof — rejecting or normalising it
    // would discard the one shape that carries information, a client declaring itself odd.
    app()->instance('request', Request::create('/login', 'POST', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => "curl/8.5.0'; DROP TABLE users; --",
    ]));

    $this->logger->record('LOGIN_FAILED', '0123456789');

    $event = SecurityEvent::query()->sole();

    expect($event->ip_address)->toBe('203.0.113.7')
        ->and($event->user_agent)->toBe("curl/8.5.0'; DROP TABLE users; --");
});

/**
 * ⚠ THE RULE THIS SERVICE EXISTS TO HONOUR, tested with the table actually gone rather than
 * with a mocked exception on the happy path.
 *
 * The scenario is a Master Admin logging in to repair a database fault. If a security-event
 * write can block authentication, one broken table locks everyone out of the system —
 * including the person who has to log in to fix it. That is not a degraded system; it is a
 * locked room with the key inside.
 */
it('never blocks when the table cannot be written, and says so in the file log', function () {
    Log::spy();

    Schema::rename('security_events', 'security_events_missing');

    try {
        // Must not throw. If this test fails, the system can lock out its own administrator.
        $this->logger->record('LOGIN_SUCCESS', '0123456789');
    } finally {
        Schema::rename('security_events_missing', 'security_events');
    }

    // The loss is loud somewhere. Swallowing silently is the failure mode BR-AT8 trades for,
    // and it is only acceptable because of this line.
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'security event'))
        ->once();

    expect(SecurityEvent::query()->count())->toBe(0);
});

it('does not open a transaction of its own', function () {
    // BR-AT8's asymmetry with BR-AT7: this write must not be tied to anything that can roll
    // it back, and it must not leave a transaction open behind it either.
    $this->logger->record('LOGIN_FAILED', '0123456789');

    expect(DB::transactionLevel())->toBe(0);
});

it('records every event type the enum allows', function () {
    foreach (SecurityEvent::EVENT_TYPES as $type) {
        $this->logger->record($type, '0123456789');
    }

    expect(SecurityEvent::query()->pluck('event_type')->all())
        ->toEqualCanonicalizing(SecurityEvent::EVENT_TYPES);
});
