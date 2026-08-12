<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Scopes\SharedTenantScope;
use App\Models\Scopes\SystemTenantScope;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Auth\MasterAdminContext;
use Illuminate\Support\Str;

/**
 * SystemTenantScope — adr/0005 decision 6's amendment note, audit-trail.spec.md §11.
 *
 * On audit_logs, `company_id IS NULL` means "a system-level event": an audited action whose
 * subject belongs to no company. Both older scope classes are wrong for that meaning, in
 * OPPOSITE directions — TenantScope hides those rows from Master Admin, whose own actions
 * they mostly are, and SharedTenantScope shows them to everybody.
 *
 * ⚠ THE NEGATIVE CASES ARE THE POINT OF THIS FILE. Asserting only that Master Admin sees the
 * NULL rows passes just as happily against SharedTenantScope, which would be a silent
 * disclosure of every group-level administrative action to every HR in the group. What
 * separates the two classes is who does NOT see them.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->tursenia = Company::factory()->subsidiary($this->ahs)->create(['code' => 'TURSENIA']);
});

/** An audit row attributed to a company, or — with null — a system-level one. */
function auditRow(?Company $company, string $action = 'employee.update'): AuditLog
{
    return AuditLog::create([
        'batch_id' => (string) Str::uuid(),
        'company_id' => $company?->id,
        'user_id' => null,
        'action' => $action,
        'auditable_type' => Employee::class,
        'auditable_id' => 1,
        'field' => 'position_id',
        'old_value' => '3',
        'new_value' => '7',
        'old_label' => 'Admin Executive',
        'new_label' => 'Senior Admin Executive',
    ]);
}

/** An ordinary STANDARD account, employed by the given company, holding the given role. */
function accountHolding(string $role, Company $employer): User
{
    $employee = Employee::factory()->forCompany($employer)->create();

    EmployeeRole::factory()
        ->role($role)
        ->forCompany($employer)
        ->create(['employee_id' => $employee->id]);

    return User::factory()->forEmployee($employee)->create();
}

it('shows rows inside the account read scope, exactly as TenantScope would', function () {
    $mine = auditRow($this->aim);
    $theirs = auditRow($this->tursenia);

    $this->actingAs(accountHolding('HR', $this->aim));

    expect(AuditLog::query()->pluck('id'))
        ->toContain($mine->id)
        ->not->toContain($theirs->id);
});

it('shows a system-level row to Master Admin', function () {
    $systemRow = auditRow(null, 'master_admin.scope_bypass');

    $this->actingAs(User::factory()->masterAdmin()->create());

    expect(AuditLog::query()->pluck('id'))->toContain($systemRow->id);
});

/**
 * ⚠ THE CASE THAT DISTINGUISHES THIS CLASS FROM SharedTenantScope.
 *
 * Under SharedTenantScope this row would be visible — NULL there means "shared with
 * everyone". Here it means "attributable to no company", and a subsidiary-employed HR has no
 * business reading a group-level administrative action.
 */
it('hides a system-level row from an HR employed by a subsidiary', function () {
    $systemRow = auditRow(null, 'master_admin.scope_bypass');
    $ownRow = auditRow($this->aim);

    $this->actingAs(accountHolding('HR', $this->aim));

    $visible = AuditLog::query()->pluck('id');

    expect($visible)->not->toContain($systemRow->id)   // system-level: hidden
        ->toContain($ownRow->id);                      // own company: still visible

    // And it is hidden, not merely absent from a list — a direct lookup must miss too.
    expect(AuditLog::query()->find($systemRow->id))->toBeNull();
});

/**
 * ⚠ The stronger half of the same rule, and the reason the FULL check cannot go through read
 * scope.
 *
 * An HR employed by AHS reads EVERY company (adr/0004 decision 1). If the NULL rows were
 * gated on "is your read scope group-wide" rather than on system_access, this account would
 * see them. It must not: a group-wide read scope is a set of companies, and a system-level
 * row belongs to none of them.
 */
it('hides a system-level row from an HR employed by the parent, whose read scope is group-wide', function () {
    $systemRow = auditRow(null, 'user.system_access_changed');
    $aimRow = auditRow($this->aim);
    $tursRow = auditRow($this->tursenia);

    $this->actingAs(accountHolding('HR', $this->ahs));

    $visible = AuditLog::query()->pluck('id');

    expect($visible)->toContain($aimRow->id)           // group-wide: every company
        ->toContain($tursRow->id)
        ->not->toContain($systemRow->id);              // but never the system-level row
});

it('hides a system-level row from a VIEW_ONLY account, which also reads group-wide', function () {
    // VIEW_ONLY reaches group-wide reads through the ordinary resolver, not by being
    // privileged (adr/0005 decision 2). Only FULL sees system-level rows.
    $systemRow = auditRow(null, 'master_admin.scope_bypass');

    $this->actingAs(User::factory()->viewOnly()->create());

    expect(AuditLog::query()->pluck('id'))->not->toContain($systemRow->id);
});

it('leaves queries unscoped when nobody is authenticated', function () {
    // Console commands, seeders, queue workers. Same carve-out as TenantScope: throwing here
    // would break every artisan command, and route middleware is what protects HTTP.
    auditRow(null);
    auditRow($this->aim);

    expect(AuditLog::query()->count())->toBe(2);
});

it('lifts the scope entirely inside the master admin context', function () {
    $orphanRow = auditRow($this->tursenia);
    $this->tursenia->delete(); // soft-deleted: drops out of every resolved read scope

    $this->actingAs(User::factory()->masterAdmin()->create());

    // Outside the context a FULL account is SCOPED, not bypassed — it happens to see every
    // company because its read scope resolves to every company, so a row belonging to a
    // soft-deleted one disappears (adr/0005 decision 5).
    expect(AuditLog::query()->pluck('id'))->not->toContain($orphanRow->id);

    $inside = app(MasterAdminContext::class)->run(
        'Audit review: rows stranded by a soft-deleted company',
        fn () => AuditLog::query()->pluck('id')->all()
    );

    expect($inside)->toContain($orphanRow->id);
});

it('is declared on audit_logs and is the only scope that model carries', function () {
    $scopes = array_keys((new AuditLog())->getGlobalScopes());

    expect($scopes)->toContain(SystemTenantScope::class)
        ->not->toContain(TenantScope::class)
        ->not->toContain(SharedTenantScope::class);
});
