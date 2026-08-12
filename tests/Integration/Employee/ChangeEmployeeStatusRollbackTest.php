<?php

use App\Actions\Employee\ChangeEmployeeStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\EmployeeStatusHistory;
use App\Models\PolicyConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⚠ THE TEST THE TRANSACTION EXISTS FOR — employee-master.spec.md §5.3,
 * auth-rbac.spec.md BR-A15, audit-trail.spec.md BR-AT7.
 *
 * A freeze that failed halfway would leave an employee holding their old status with every
 * role revoked — worse than either outcome alone, and invisible until somebody tried to
 * approve something and found the approver had no authority.
 *
 * ⚠ IN tests/Integration BECAUSE IT NEEDS DDL. Breaking the audit table means renaming it,
 * and MySQL commits implicitly on DDL — under RefreshDatabase that commits the wrapping
 * transaction, so the employee's new status would already be saved and the test would report
 * a rollback failure that never happened. It did exactly that on the first run. The
 * truncation-based suite has no wrapping transaction to lose.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

    foreach ([$this->ahs, $this->aim] as $company) {
        foreach (['auth.throttle.tier_4.attempts' => '12', 'auth.account.expiry_days' => '10'] as $key => $value) {
            PolicyConfiguration::create([
                'company_id' => $company->id,
                'key' => $key,
                'value' => $value,
                'effective_from' => now()->toDateString(),
            ]);
        }
    }

    $this->actingAs(User::factory()->forEmployee(
        Employee::factory()->forCompany($this->ahs)->create()
    )->create());
});

it('leaves nothing behind when the audit write fails partway through a freeze', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create(['staff_status' => 'CONFIRMED']);
    $account = User::factory()->forEmployee($employee)->create();

    EmployeeRole::factory()->role('MANAGER')->forCompany($this->aim)->create(['employee_id' => $employee->id]);

    DB::table('sessions')->insert([
        'id' => 'sess-rollback', 'user_id' => $account->id, 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);

    // ⚠ Renamed BEFORE the Action runs, so the DDL's implicit commit lands outside the
    // transaction under test rather than destroying it.
    Schema::rename('audit_logs', 'audit_logs_missing');

    try {
        app(ChangeEmployeeStatus::class)->execute($employee, 'TERMINATED', '2026-06-30');
        $this->fail('the action must not complete when the audit write fails');
    } catch (Throwable) {
        // expected — BR-AT7: a failed audit write rolls the action back.
    } finally {
        Schema::rename('audit_logs_missing', 'audit_logs');
    }

    // All four effects gone together. Any one of them surviving is the partial state this
    // design exists to make impossible.
    expect($employee->fresh()->staff_status)->toBe('CONFIRMED')
        ->and(EmployeeStatusHistory::query()->count())->toBe(0)
        ->and($employee->roles()->count())->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $account->id)->count())->toBe(1);
});

/**
 * ⚠ The mirror image: when it succeeds, all four land. Asserting only the rollback would
 * pass against an Action that never did anything at all.
 */
it('lands every effect together when the freeze succeeds', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create(['staff_status' => 'CONFIRMED']);
    $account = User::factory()->forEmployee($employee)->create();

    EmployeeRole::factory()->role('MANAGER')->forCompany($this->aim)->create(['employee_id' => $employee->id]);

    DB::table('sessions')->insert([
        'id' => 'sess-ok', 'user_id' => $account->id, 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);

    app(ChangeEmployeeStatus::class)->execute($employee, 'TERMINATED', '2026-06-30');

    expect($employee->fresh()->staff_status)->toBe('TERMINATED')
        ->and(EmployeeStatusHistory::query()->count())->toBe(1)
        ->and($employee->roles()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $account->id)->count())->toBe(0);
});
