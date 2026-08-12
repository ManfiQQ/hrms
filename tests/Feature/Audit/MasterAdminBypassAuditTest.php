<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Auth\MasterAdminContext;

/**
 * The audit half of adr/0005 decision 5, deferred since PR #10 and closed here.
 *
 * Until now MasterAdminContext captured the reason and dropped it: "explicit, never ambient"
 * held, "audited" did not. The most powerful read in the system was the one that left no
 * trace, which is expressly not what that decision says.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->masterAdmin = User::factory()->masterAdmin()->create();
});

it('writes the bypass, its reason and its actor to audit_logs', function () {
    $this->actingAs($this->masterAdmin);

    app(MasterAdminContext::class)->run(
        'Data repair: employee stranded by a soft-deleted company',
        fn () => Employee::query()->count()
    );

    $row = AuditLog::query()->sole();

    expect($row->action)->toBe('master_admin.scope_bypass')
        ->and($row->reason)->toBe('Data repair: employee stranded by a soft-deleted company')
        ->and($row->user_id)->toBe($this->masterAdmin->id)
        ->and($row->auditable_type)->toBe(User::class)
        ->and($row->auditable_id)->toBe($this->masterAdmin->id)
        ->and($row->old_value)->toBe('scoped')
        ->and($row->new_value)->toBe('bypassed');
});

/**
 * ⚠ A system-level row: the actor belongs to no company, so the row is attributable to none
 * — which is what NULL means on this column, and why only Master Admin can read it back
 * (§11). A row nobody can read afterwards would satisfy the letter of "audited" and none of
 * its purpose.
 */
it('records the bypass as a system-level row with no company', function () {
    $this->actingAs($this->masterAdmin);

    app(MasterAdminContext::class)->run('repair', fn () => null);

    expect(AuditLog::query()->sole()->company_id)->toBeNull();
    expect(AuditLog::query()->sole()->isSystemLevel())->toBeTrue();
});

/**
 * ⚠ The bypass HAPPENED whether or not the work inside it succeeded, and the failed one is
 * the more interesting of the two to review. A record that vanished with the callback would
 * lose exactly the case worth keeping.
 */
it('keeps the record when the callback throws', function () {
    $this->actingAs($this->masterAdmin);

    expect(fn () => app(MasterAdminContext::class)->run(
        'repair that went wrong',
        fn () => throw new RuntimeException('the repair failed')
    ))->toThrow(RuntimeException::class, 'the repair failed');

    expect(AuditLog::query()->count())->toBe(1)
        ->and(AuditLog::query()->sole()->reason)->toBe('repair that went wrong');

    // And the scope is still restored, as it always was.
    expect(app(MasterAdminContext::class)->isActive())->toBeFalse();
});

it('still refuses a bypass with no stated reason, and writes nothing', function () {
    $this->actingAs($this->masterAdmin);

    expect(fn () => app(MasterAdminContext::class)->run('  ', fn () => null))
        ->toThrow(RuntimeException::class);

    expect(AuditLog::query()->count())->toBe(0);
});

/**
 * ⚠ Attribution is not optional, and this follows from the audit requirement rather than
 * being a new restriction: a bypass nobody can be attributed to is the ambient bypass
 * decision 5 rejects, and audit_logs has no nullable subject to record it against.
 *
 * Console and queue contexts lose nothing — with no authenticated user the tenant scopes
 * already run unscoped, so there is nothing there for this class to lift.
 */
it('refuses to run with no authenticated account', function () {
    expect(fn () => app(MasterAdminContext::class)->run('console repair', fn () => null))
        ->toThrow(RuntimeException::class, 'requires an authenticated account');

    expect(AuditLog::query()->count())->toBe(0);
});

it('records one row per bypass, so repeated entries are countable', function () {
    $this->actingAs($this->masterAdmin);

    app(MasterAdminContext::class)->run('first repair', fn () => null);
    app(MasterAdminContext::class)->run('second repair', fn () => null);

    expect(AuditLog::query()->pluck('reason')->all())
        ->toBe(['first repair', 'second repair']);
});

it('is readable afterwards by the Master Admin who made it', function () {
    $this->actingAs($this->masterAdmin);

    app(MasterAdminContext::class)->run('repair', fn () => null);

    // Outside the context, through the ordinary scope. If this fails the write is
    // technically present and practically useless.
    expect(AuditLog::query()->count())->toBe(1);
});
