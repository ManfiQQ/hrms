<?php

namespace App\Support\Audit;

/**
 * The canonical list of fields that must be audited (audit-trail.spec.md BR-AT13).
 *
 * ⚠ THIS CLASS IS THE LIST. The specs reference it; they do not restate its contents. A list
 * in markdown plus a copy in code would be two records of one fact, and the copy is the one
 * that goes stale — the same objection that rejected mirroring employee_status_history
 * (BR-AT5), a stored read-scope override (adr/0004 decision 1) and is_enabled (adr/0003
 * decision 1).
 *
 * The owning module's spec says WHICH fields belong here and WHY. This file says what they
 * are, in a form the architecture test can read.
 *
 * Adding a pair here without an Action to write it fails the test. That is the point: the
 * realistic Phase 2 failure is the spec growing while the code does not.
 */
class AuditedFields
{
    /**
     * Model class => list of field names that must produce an audit row when they change.
     *
     * @var array<class-string, list<string>>
     */
    public const FIELDS = [
        // Who moved this person to RESIGNED, when, and why. The ledger
        // (employee_status_history) answers what the status WAS on a date; this answers who
        // changed it — two different facts about one event, which is why recording both is
        // not the duplication adr/0003 decision 8 forbids.
        //
        // Written by App\Actions\Employee\ChangeEmployeeStatus, which declares the matching
        // AUDITS constant. The architecture test fails in both directions: an entry here
        // with no Action behind it, and an Action auditing a field absent from this list.
        \App\Models\Employee::class => ['staff_status', 'employee_no'],

        // ⚠ Account operations, and note what is NOT here: `password` and
        // `activation_token`. audit_logs is readable by HR and ASSISTANT_DIRECTOR within
        // their read scope (BR-AT9), so auditing a credential's VALUE would hand it to every
        // reader of that screen. The derived facts below record that the operation happened,
        // which is the accountable part; the secret is not.
        \App\Models\User::class => ['phone_no', 'password_changed_at', 'locked_until', 'activation_expires_at', 'system_access'],
    ];

    /**
     * Every audited pair, flattened.
     *
     * @return list<array{model: class-string, field: string}>
     */
    public static function pairs(): array
    {
        $pairs = [];

        foreach (self::FIELDS as $model => $fields) {
            foreach ($fields as $field) {
                $pairs[] = ['model' => $model, 'field' => $field];
            }
        }

        return $pairs;
    }

    public static function isEmpty(): bool
    {
        return self::pairs() === [];
    }
}
