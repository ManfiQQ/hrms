<?php

namespace App\Exceptions\Audit;

use RuntimeException;

/**
 * An audit write attempted with no database transaction open.
 *
 * ⚠ Why this throws instead of generating a batch id and carrying on.
 *
 * BR-AT12 binds batch_id to the transaction: the batch boundary IS the transaction
 * boundary, because BR-AT7 already requires the audit rows to be written inside the same
 * transaction as the action. A write with no transaction open is therefore not a batch
 * without an id — it is an action whose change and whose audit row can land separately,
 * which is the one guarantee this table exists to provide.
 *
 * A silently-minted single-use UUID would produce a one-row batch that is
 * INDISTINGUISHABLE from a legitimate one-field change. The only fact worth knowing — that
 * this write was unguarded — would be erased at the moment it was created. A fallback that
 * hides its own failure mode is worse than no fallback.
 *
 * The fix at a call site is never to catch this. It is to wrap the action in
 * DB::transaction(), which is what BR-AT7 required of it anyway.
 */
class AuditWriteOutsideTransactionException extends RuntimeException
{
    public static function for(string $action, string $subject, string $field): self
    {
        return new self(
            "Refusing to audit {$action} on {$subject}.{$field}: no database transaction is open. "
            .'audit_logs must be written inside the same transaction as the action it records, '
            .'so that a failed audit write rolls the action back (audit-trail.spec.md BR-AT7). '
            .'batch_id is bound to that transaction (BR-AT12). Wrap the action in '
            .'DB::transaction() — do not catch this.'
        );
    }
}
