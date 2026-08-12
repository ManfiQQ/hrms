<?php

use App\Console\Commands\PruneSecurityEvents;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PolicyConfiguration;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The BR-AT11 retention sweep — audit-trail.spec.md §5.6.
 *
 * ⚠ The dangerous failure here is not deleting too little; it is deleting a row that should
 * have been kept forever. Every test below asserts BOTH sides of the predicate, because the
 * failure mode is a rewrite that drops the `whereNull('user_id')` half — leaving a command
 * that silently erases the group's entire security history on its next scheduled run.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

    PolicyConfiguration::create([
        'company_id' => $this->ahs->id,
        'key' => PruneSecurityEvents::RETENTION_KEY,
        'value' => '90',
        'effective_from' => now()->toDateString(),
    ]);
});

function securityEventAged(int $daysOld, ?User $user = null): SecurityEvent
{
    $event = SecurityEvent::create([
        'user_id' => $user?->getKey(),
        'event_type' => 'LOGIN_FAILED',
        'identifier' => '0123456789',
    ]);

    // created_at carries a DB default, so it is set after the fact rather than through the
    // model — which refuses updates (BR-AT6).
    SecurityEvent::query()->whereKey($event->id)->toBase()
        ->update(['created_at' => now()->subDays($daysOld)]);

    return $event->fresh();
}

function anAccount(Company $company): User
{
    return User::factory()->forEmployee(
        Employee::factory()->forCompany($company)->create()
    )->create();
}

/**
 * ⚠ Both halves in one test. A test covering only the expiring row cannot see a predicate
 * that has lost its user_id condition — it would pass while the command deleted everything.
 */
it('prunes only unattributed events past the window, and keeps attributed ones of the same age', function () {
    $expiredUnattributed = securityEventAged(91);
    $freshUnattributed = securityEventAged(89);
    $expiredAttributed = securityEventAged(91, anAccount($this->aim));
    $ancientAttributed = securityEventAged(4000, anAccount($this->aim));

    $this->artisan('security-events:prune')->assertExitCode(0);

    $remaining = SecurityEvent::query()->pluck('id');

    expect($remaining)->not->toContain($expiredUnattributed->id)  // no subject, past the window
        ->toContain($freshUnattributed->id)                       // no subject, inside it
        ->toContain($expiredAttributed->id)                       // has a subject: kept forever
        ->toContain($ancientAttributed->id);                      // eleven years old, still kept
});

it('never touches audit_logs', function () {
    AuditLog::create([
        'batch_id' => (string) Str::uuid(),
        'company_id' => null,
        'action' => 'master_admin.scope_bypass',
        'auditable_type' => User::class,
        'auditable_id' => 1,
        'field' => 'tenant_scope',
        'old_value' => 'scoped',
        'new_value' => 'bypassed',
    ]);

    AuditLog::query()->toBase()->update(['created_at' => now()->subDays(4000)]);

    $this->artisan('security-events:prune')->assertExitCode(0);

    // audit_logs is kept forever, without exception (BR-AT11). Age is not a reason.
    expect(AuditLog::query()->count())->toBe(1);
});

it('reads the window from the parent company and ignores a subsidiary row', function () {
    // Two answers to a group-wide question is the drift this project rejects everywhere
    // else. The subsidiary's 1-day value must have no effect.
    PolicyConfiguration::create([
        'company_id' => $this->aim->id,
        'key' => PruneSecurityEvents::RETENTION_KEY,
        'value' => '1',
        'effective_from' => now()->toDateString(),
    ]);

    $insideParentWindow = securityEventAged(30);

    $this->artisan('security-events:prune')->assertExitCode(0);

    expect(SecurityEvent::query()->pluck('id'))->toContain($insideParentWindow->id);
});

/**
 * ⚠ Aborting is the safe direction. A default compiled into the command would be a second
 * source for a number conventions.md §5 says must live in configuration — and the failure
 * mode of guessing here is deleting rows that should have been kept.
 */
it('refuses to run and deletes nothing when the retention key is missing', function () {
    PolicyConfiguration::query()->where('key', PruneSecurityEvents::RETENTION_KEY)->forceDelete();

    $old = securityEventAged(4000);

    $this->artisan('security-events:prune')->assertExitCode(1);

    expect(SecurityEvent::query()->pluck('id'))->toContain($old->id);
});

it('refuses a non-numeric or non-positive window', function (string $value) {
    PolicyConfiguration::query()
        ->where('key', PruneSecurityEvents::RETENTION_KEY)
        ->toBase()
        ->update(['value' => $value]);

    $old = securityEventAged(4000);

    $this->artisan('security-events:prune')->assertExitCode(1);

    expect(SecurityEvent::query()->pluck('id'))->toContain($old->id);
})->with(['forever', '0', '-30', '']);

it('reports without deleting on a dry run', function () {
    $old = securityEventAged(4000);

    $this->artisan('security-events:prune --dry-run')->assertExitCode(0);

    expect(SecurityEvent::query()->pluck('id'))->toContain($old->id);
});

it('takes no filter arguments', function () {
    // A prune command that accepts a --where is a delete capability with extra steps
    // (§5.6). The predicate is fixed in the command and nothing about it comes from a
    // caller.
    $definition = (new PruneSecurityEvents())->getDefinition();

    expect(array_keys($definition->getArguments()))->toBe([])
        ->and(array_keys($definition->getOptions()))->toBe(['dry-run']);
});

it('is scheduled rather than reachable on demand', function () {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command ?? '', 'security-events:prune'));

    expect($events)->toHaveCount(1);
});
