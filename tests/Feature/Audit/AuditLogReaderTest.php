<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Audit\AuditLogReader;
use App\Support\Audit\SalaryFields;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * AuditLogReader — audit-trail.spec.md §5.3, BR-AT9 and BR-AT10.
 *
 * ⚠ THE SALARY NEGATIVES ARE THE POINT OF THIS FILE. adr/0003 decision 5 is unconditional:
 * no HR reads salary, at ANY scope. The audit log is the easiest back door to overlook,
 * because it is the one table that writes every value in the database down a second time —
 * and a leak here looks like an ordinary admin screen working correctly.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->tursenia = Company::factory()->subsidiary($this->ahs)->create(['code' => 'TURSENIA']);

    // No model declares SALARY_FIELDS yet — Payroll is Phase 2 — so the filter is given a
    // stand-in pair. Testing it against an empty registry would assert nothing at all, which
    // is precisely the failure SalaryFieldDeclarationTest exists to prevent.
    $this->salaryPair = ['model' => Employee::class, 'field' => 'basic_salary'];

    $salaryFields = Mockery::mock(SalaryFields::class);
    $salaryFields->shouldReceive('pairs')->andReturn([$this->salaryPair]);
    $salaryFields->shouldReceive('covers')->andReturnUsing(
        fn (string $model, string $field) => $model === Employee::class && $field === 'basic_salary'
    );
    app()->instance(SalaryFields::class, $salaryFields);

    $this->reader = app(AuditLogReader::class);
});

function readerAccount(string $role, Company $employer, ?Company $roleAt = null): User
{
    $employee = Employee::factory()->forCompany($employer)->create();

    EmployeeRole::factory()
        ->role($role)
        ->forCompany($roleAt ?? $employer)
        ->create(['employee_id' => $employee->id]);

    return User::factory()->forEmployee($employee)->create();
}

function auditRowFor(?Company $company, string $field = 'position_id'): AuditLog
{
    return AuditLog::create([
        'batch_id' => (string) Str::uuid(),
        'company_id' => $company?->id,
        'action' => 'employee.update',
        'auditable_type' => Employee::class,
        'auditable_id' => 1,
        'field' => $field,
        'old_value' => '1',
        'new_value' => '2',
    ]);
}

it('lets Master Admin read everything, salary rows and system-level rows included', function () {
    $salary = auditRowFor($this->aim, 'basic_salary');
    $system = auditRowFor(null);

    $this->actingAs(User::factory()->masterAdmin()->create());

    expect($this->reader->auditLogs(auth()->user())->pluck('id'))
        ->toContain($salary->id)
        ->toContain($system->id);
});

it('lets HR read ordinary rows within its read scope', function () {
    $mine = auditRowFor($this->aim);
    $theirs = auditRowFor($this->tursenia);

    $this->actingAs(readerAccount('HR', $this->aim));

    expect($this->reader->auditLogs(auth()->user())->pluck('id'))
        ->toContain($mine->id)
        ->not->toContain($theirs->id);
});

/**
 * ⚠ THE CASE THE WHOLE FILTER EXISTS FOR — and the one most likely to be got wrong, because
 * a group-wide read scope makes every other row correctly visible.
 *
 * An HR employed by AHS reads every company (adr/0004 decision 1). They must STILL not see a
 * single salary row. Scope answers WHICH companies; role answers WHAT DATA within them, and
 * an implementation where the two agree has merged them (conventions.md §2).
 */
it('hides salary rows from an AHS-employed HR whose read scope is the whole group', function () {
    $aimSalary = auditRowFor($this->aim, 'basic_salary');
    $tursSalary = auditRowFor($this->tursenia, 'basic_salary');
    $ordinary = auditRowFor($this->tursenia);

    $this->actingAs(readerAccount('HR', $this->ahs));

    $visible = $this->reader->auditLogs(auth()->user())->pluck('id');

    expect($visible)->toContain($ordinary->id)          // group-wide reads still work
        ->not->toContain($aimSalary->id)                // and not one salary row
        ->not->toContain($tursSalary->id);
});

it('hides salary rows from a subsidiary-employed HR too', function () {
    $salary = auditRowFor($this->aim, 'basic_salary');
    $ordinary = auditRowFor($this->aim);

    $this->actingAs(readerAccount('HR', $this->aim));

    expect($this->reader->auditLogs(auth()->user())->pluck('id'))
        ->toContain($ordinary->id)
        ->not->toContain($salary->id);
});

it('hides salary rows from ASSISTANT_DIRECTOR, exactly as from HR', function () {
    $salary = auditRowFor($this->aim, 'basic_salary');

    $this->actingAs(readerAccount('ASSISTANT_DIRECTOR', $this->ahs));

    expect($this->reader->auditLogs(auth()->user())->pluck('id'))->not->toContain($salary->id);
});

/**
 * ⚠ Filtered out ENTIRELY, not masked. A masked row still discloses THAT this employee's
 * salary changed, on what date and by whom — which is material, and which ACCOUNT-only means
 * HR does not get. Asserted on the count, because an aggregate is a leak with a smaller
 * surface, not a different rule.
 */
it('removes salary rows from counts and from a direct lookup, not just from the list', function () {
    $salary = auditRowFor($this->aim, 'basic_salary');
    auditRowFor($this->aim);

    $this->actingAs(readerAccount('HR', $this->ahs));

    expect($this->reader->auditLogs(auth()->user())->count())->toBe(1)
        ->and($this->reader->auditLogs(auth()->user())->find($salary->id))->toBeNull();
});

it('lets ACCOUNT read salary rows at the company where it holds the role', function () {
    $salary = auditRowFor($this->aim, 'basic_salary');

    $this->actingAs(readerAccount('ACCOUNT', $this->aim));

    expect($this->reader->auditLogs(auth()->user())->pluck('id'))->toContain($salary->id);
});

/**
 * ⚠ Salary visibility is per company because the ACCOUNT role is held per company
 * (adr/0003 decision 5). Read scope says which companies; the role says what data within
 * them. An ACCOUNT at AHS reading a subsidiary's salary rows would have merged the two.
 */
it('does not let ACCOUNT at one company read another company\'s salary rows', function () {
    $ahsSalary = auditRowFor($this->ahs, 'basic_salary');
    $aimSalary = auditRowFor($this->aim, 'basic_salary');

    // Employed by AHS, so read scope is the whole group; ACCOUNT held at AHS only.
    $this->actingAs(readerAccount('ACCOUNT', $this->ahs));

    expect($this->reader->auditLogs(auth()->user())->pluck('id'))
        ->toContain($ahsSalary->id)
        ->not->toContain($aimSalary->id);
});

/**
 * ⚠ A revoked role is not current authority, and this is the single most dangerous omission
 * in the module: a query missing `revoked_date IS NULL` returns revoked authority as live
 * and NOTHING FAILS. Asserted through RoleChecker rather than by hand-writing the condition.
 */
it('stops reading salary rows once the ACCOUNT role is revoked', function () {
    $salary = auditRowFor($this->aim, 'basic_salary');

    $user = readerAccount('ACCOUNT', $this->aim);
    $this->actingAs($user);

    expect($this->reader->auditLogs($user)->pluck('id'))->toContain($salary->id);

    EmployeeRole::query()
        ->where('employee_id', $user->employee_id)
        ->update(['revoked_date' => now()->toDateString()]);

    // Also grant HR so the account still passes authorization — otherwise this would prove
    // only that a role-less account reads nothing, which is a different rule.
    EmployeeRole::factory()->role('HR')->forCompany($this->aim)
        ->create(['employee_id' => $user->employee_id]);

    expect($this->reader->auditLogs($user->fresh())->pluck('id'))->not->toContain($salary->id);
});

it('refuses an account holding none of the reader roles', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create();
    $ordinaryStaff = User::factory()->forEmployee($employee)->create();

    expect(fn () => $this->reader->auditLogs($ordinaryStaff))
        ->toThrow(AuthorizationException::class);
});

it('lets HR read security events in scope but never the unattributed ones', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create();
    $account = User::factory()->forEmployee($employee)->create();

    $attributed = SecurityEvent::create([
        'user_id' => $account->id, 'event_type' => 'LOGIN_FAILED',
        'identifier' => '0123456789', 'company_id' => $this->aim->id,
    ]);

    // No account, therefore no employer, therefore no company — so it falls inside no
    // narrower read scope and is Master Admin only (BR-AT9).
    $unattributed = SecurityEvent::create([
        'user_id' => null, 'event_type' => 'LOGIN_FAILED',
        'identifier' => '0111111111', 'company_id' => null,
    ]);

    $this->actingAs(readerAccount('HR', $this->aim));

    expect($this->reader->securityEvents(auth()->user())->pluck('id'))
        ->toContain($attributed->id)
        ->not->toContain($unattributed->id);

    $this->actingAs(User::factory()->masterAdmin()->create());

    expect($this->reader->securityEvents(auth()->user())->pluck('id'))
        ->toContain($unattributed->id);
});

it('gives ACCOUNT no security events at all', function () {
    // It reads the most data in the system and administers none of it; account security is
    // administration (auth-rbac.spec.md §6).
    SecurityEvent::create([
        'user_id' => null, 'event_type' => 'LOGIN_FAILED', 'identifier' => '0123456789',
    ]);

    $this->actingAs(readerAccount('ACCOUNT', $this->aim));

    expect($this->reader->securityEvents(auth()->user())->count())->toBe(0);
});
