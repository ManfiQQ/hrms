<?php

use App\Exceptions\Audit\AuditWriteOutsideTransactionException;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * AuditLogger — audit-trail.spec.md §5.1, BR-AT7 and BR-AT12.
 *
 * ⚠ These tests live in tests/Integration for a reason. RefreshDatabase would hold a
 * transaction open around each one, so DB::transactionLevel() would never reach 0, the batch
 * would never be released, and the two tests that matter most — that a second transaction
 * gets a NEW batch id, and that a rollback releases the old one — would pass without
 * exercising anything.
 */
beforeEach(function () {
    $this->logger = app(AuditLogger::class);
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->subject = Employee::factory()->forCompany($this->ahs)->create();
});

it('refuses to write outside a transaction, and writes nothing', function () {
    expect(DB::transactionLevel())->toBe(0);

    // Both halves asserted. A logger that throws AFTER inserting has still broken BR-AT7 —
    // the row would be there without the action, which is the mirror of the failure the
    // rule exists to prevent.
    expect(fn () => $this->logger->record(
        'employee.update', $this->subject, 'position_id', 3, 7
    ))->toThrow(AuditWriteOutsideTransactionException::class);

    expect(AuditLog::query()->count())->toBe(0);
});

it('does not open a transaction of its own to satisfy its own precondition', function () {
    // If the service wrapped itself in DB::transaction() the call above would succeed, and
    // BR-AT7's guarantee — the action and its audit row land together or not at all — would
    // be quietly gone while every test still passed.
    try {
        $this->logger->record('employee.update', $this->subject, 'level', 'STAFF', 'SUPERVISOR');
    } catch (AuditWriteOutsideTransactionException) {
        // expected
    }

    expect(DB::transactionLevel())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('gives every write in one transaction the same batch id, across models', function () {
    DB::transaction(function () {
        $this->logger->record('employee.transfer', $this->subject, 'company_id', 1, 2);
        $this->logger->record('employee.transfer', $this->subject, 'department_id', 4, 9);
        $this->logger->record('employee.transfer', $this->ahs, 'name', 'Old', 'New');
    });

    $batchIds = AuditLog::query()->pluck('batch_id')->unique();

    expect(AuditLog::query()->count())->toBe(3)
        ->and($batchIds)->toHaveCount(1);
});

/**
 * ⚠ The test that proves the batch is RELEASED rather than merely shared.
 *
 * Without it, a logger that generates one id per process passes the test above forever — and
 * every action in a request would render as one batch.
 */
it('gives a second transaction a different batch id', function () {
    DB::transaction(fn () => $this->logger->record('a.one', $this->subject, 'f', 1, 2));
    $first = AuditLog::query()->value('batch_id');

    DB::transaction(fn () => $this->logger->record('a.two', $this->subject, 'f', 2, 3));
    $second = AuditLog::query()->orderByDesc('id')->value('batch_id');

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($second)->not->toBe($first);
});

/**
 * ⚠ Rollback is the path that leaks a stale batch id if only the commit path resets.
 */
it('releases the batch on rollback too, and takes the rows with it', function () {
    try {
        DB::transaction(function () {
            $this->logger->record('employee.update', $this->subject, 'level', 'STAFF', 'HOD');

            throw new RuntimeException('the action failed after its audit row');
        });
    } catch (RuntimeException) {
        // expected
    }

    // BR-AT7: the audit row went back with the action.
    expect(AuditLog::query()->count())->toBe(0)
        ->and($this->logger->currentBatchId())->toBeNull();

    DB::transaction(fn () => $this->logger->record('employee.update', $this->subject, 'level', 'STAFF', 'HOD'));

    expect(AuditLog::query()->count())->toBe(1);
});

it('keeps nested transactions in the outermost batch and does not release on the inner commit', function () {
    DB::transaction(function () {
        $this->logger->record('outer.action', $this->subject, 'a', 1, 2);

        // A savepoint commit is not the action landing, so it must not end the batch.
        DB::transaction(function () {
            $this->logger->record('inner.action', $this->subject, 'b', 1, 2);
        });

        $this->logger->record('outer.action', $this->subject, 'c', 1, 2);
    });

    expect(AuditLog::query()->pluck('batch_id')->unique())->toHaveCount(1)
        ->and(AuditLog::query()->count())->toBe(3);
});

it('reports no batch outside a transaction', function () {
    expect($this->logger->currentBatchId())->toBeNull();

    DB::transaction(function () {
        $this->logger->record('a.one', $this->subject, 'f', 1, 2);
        expect($this->logger->currentBatchId())->not->toBeNull();
    });

    expect($this->logger->currentBatchId())->toBeNull();
});

it('writes one row per changed field and none for a no-op', function () {
    DB::transaction(function () {
        $this->logger->recordChanges('employee.update', $this->subject, [
            'position_id' => [3, 7],
            'level' => ['STAFF', 'SUPERVISOR'],
            'nickname' => ['Ali', 'Ali'],   // unchanged: not an audit row
        ]);
    });

    expect(AuditLog::query()->pluck('field')->all())
        ->toEqualCanonicalizing(['position_id', 'level']);
});

it('records the action, the polymorphic subject, and the reason', function () {
    DB::transaction(fn () => $this->logger->record(
        'master_admin.scope_bypass',
        $this->subject,
        'company_id',
        1,
        2,
        'Data repair: employee stranded by a soft-deleted company',
    ));

    $row = AuditLog::query()->sole();

    expect($row->action)->toBe('master_admin.scope_bypass')
        ->and($row->auditable_type)->toBe(Employee::class)
        ->and($row->auditable_id)->toBe($this->subject->id)
        ->and($row->reason)->toBe('Data repair: employee stranded by a soft-deleted company')
        ->and($row->old_value)->toBe('1')
        ->and($row->new_value)->toBe('2');
});

it('keeps null distinct from an empty string', function () {
    // "never set" and "set to nothing" are different facts about a record.
    DB::transaction(fn () => $this->logger->record('employee.update', $this->subject, 'nickname', null, ''));

    $row = AuditLog::query()->sole();

    expect($row->old_value)->toBeNull()
        ->and($row->new_value)->toBe('');
});

it('takes company_id and user_id from the authenticated context, not from arguments', function () {
    $user = App\Models\User::factory()->forEmployee($this->subject)->create();
    $this->actingAs($user);

    DB::transaction(fn () => $this->logger->record('employee.update', $this->subject, 'f', 1, 2));

    $row = AuditLog::query()->sole();

    expect($row->user_id)->toBe($user->id)
        ->and($row->company_id)->toBe($this->ahs->id);
});

it('writes a system-level row with a null company when the actor has no employee', function () {
    // A Master Admin belongs to no company (adr/0004 decision 4), so their actions are
    // attributable to none — which is exactly what NULL means on this column (§11).
    $this->actingAs(App\Models\User::factory()->masterAdmin()->create());

    DB::transaction(fn () => $this->logger->record(
        'master_admin.scope_bypass', $this->subject, 'company_id', 1, 2, 'repair'
    ));

    expect(AuditLog::query()->sole()->company_id)->toBeNull();
});
