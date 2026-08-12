<?php

namespace App\Services\Audit;

use App\Exceptions\Audit\AuditWriteOutsideTransactionException;
use App\Models\AuditLog;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The only permitted writer of audit_logs (audit-trail.spec.md §5.1).
 *
 * A raw insert or an AuditLog::create() elsewhere is a review failure, for the same reason a
 * raw employee_roles query is: this is the one place the transaction check and the batch id
 * are applied, and a call that goes around it skips both.
 *
 * ⚠ BR-AT12 — batch_id is bound to the DATABASE TRANSACTION. It is generated on the first
 * write inside a transaction, reused by every later write in that same transaction, and
 * released when the transaction ends — on commit and on rollback alike. The batch boundary
 * IS the transaction boundary; there is deliberately no startBatch(), because a method that
 * appeared to open a batch would be the second concept that rule exists to prevent.
 *
 * ⚠ BR-AT13 — every Action calls this service EXPLICITLY. There is no trait and no model
 * observer, and none may be added: an observer knows what changed but not WHY, cannot name
 * the action, and would audit every write indiscriminately — imports, seeders, backfills,
 * factories.
 */
class AuditLogger
{
    /** The batch in effect for the current transaction, or null when none is open. */
    private ?string $batchId = null;

    public function __construct(
        private readonly DatabaseManager $db,
        Dispatcher $events,
    ) {
        // Released on BOTH paths. A logger that resets only on commit leaks a stale batch id
        // into the next transaction after a rollback, which §8 test 31 exists to catch.
        //
        // Laravel fires these on every commit() and rollBack() call, nested ones included,
        // so the level check is what makes "outermost transaction" the boundary: a savepoint
        // commit is not the action landing (BR-AT12).
        $release = function (): void {
            if ($this->db->connection()->transactionLevel() === 0) {
                $this->batchId = null;
            }
        };

        $events->listen(TransactionCommitted::class, $release);
        $events->listen(TransactionRolledBack::class, $release);
    }

    /**
     * Record one changed field.
     *
     * @param  string  $action   what was done, e.g. employee.transfer, employee_role.grant
     * @param  Model   $subject  the polymorphic subject — not necessarily an employee (BR-AT3)
     *
     * @throws AuditWriteOutsideTransactionException when no transaction is open (BR-AT12)
     */
    public function record(
        string $action,
        Model $subject,
        string $field,
        mixed $old,
        mixed $new,
        ?string $reason = null,
    ): void {
        // ⚠ Checked BEFORE anything is written, and the service never opens a transaction of
        // its own. Opening one here would satisfy this precondition while leaving BR-AT7
        // unmet — the action could still land without its audit row.
        if ($this->db->connection()->transactionLevel() === 0) {
            throw AuditWriteOutsideTransactionException::for($action, $subject::class, $field);
        }

        $oldValue = $this->stringify($old);
        $newValue = $this->stringify($new);

        // A no-op is not an audit row.
        if ($oldValue === $newValue) {
            return;
        }

        AuditLog::create([
            'batch_id' => $this->batchId ??= (string) Str::uuid(),

            // ⚠ From the authenticated context, NEVER from arguments and never from request
            // input. company_id is null for a system-level event — an action whose subject
            // belongs to no company (§11).
            'company_id' => $this->currentCompanyId(),
            'user_id' => auth()->id(),

            'action' => $action,
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'field' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,

            // BR-AT4 — resolved AT WRITE TIME. A join at read time would render the name
            // TODAY, not the name THEN, and a record that changes retroactively is not a
            // record.
            'old_label' => $this->label($subject, $field, $old, $oldValue),
            'new_label' => $this->label($subject, $field, $new, $newValue),

            'reason' => $reason,
        ]);
    }

    /**
     * Record several changed fields on one subject — the usual entry point.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [old, new]
     */
    public function recordChanges(
        string $action,
        Model $subject,
        array $changes,
        ?string $reason = null,
    ): void {
        foreach ($changes as $field => [$old, $new]) {
            $this->record($action, $subject, $field, $old, $new, $reason);
        }
    }

    /**
     * The batch in effect, or null outside a transaction.
     *
     * For tests and assertions. Not a way to start one — under BR-AT12 only the transaction
     * does that.
     */
    public function currentBatchId(): ?string
    {
        return $this->db->connection()->transactionLevel() === 0 ? null : $this->batchId;
    }

    /**
     * The company this action is attributable to, or null for a system-level event.
     *
     * Null is a MEANINGFUL value here, not a fallback: a Master Admin changing another
     * Master Admin's system_access has no company to name, and SystemTenantScope shows those
     * rows to Master Admin alone (§11).
     */
    private function currentCompanyId(): ?int
    {
        return auth()->user()?->employee?->company_id;
    }

    /**
     * The display text for a value, as it read at the time.
     *
     * A subject may implement auditLabel() to render a foreign key as the text it stood for
     * — department_id = 7 as "Logistics". Without it the label is the value's own string
     * form, which BR-AT4 accepts as redundant-but-uniform for enums and scalars: one row
     * shape costs a few bytes and avoids per-type branching in every reader.
     */
    private function label(Model $subject, string $field, mixed $value, ?string $fallback): ?string
    {
        if (method_exists($subject, 'auditLabel')) {
            return $subject->auditLabel($field, $value) ?? $fallback;
        }

        return $fallback;
    }

    /** Values are stored as TEXT; null stays null so "unset" and "empty string" stay apart. */
    private function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => (string) $value,
        };
    }
}
