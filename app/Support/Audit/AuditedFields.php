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
        // Employee Master is the first module to fill this. Nothing is audited yet because
        // no Action exists anywhere in the codebase.
    ];

    /**
     * ⚠ Why the registry may be empty right now, and until when.
     *
     * An architecture test over an empty set passes forever while checking nothing — it is
     * the kind of test nobody notices has died. The BR-AT13 guard therefore FAILS on an
     * empty registry unless this constant is present, which makes "not started yet" a
     * deliberate statement with an expiry rather than a silent gap.
     *
     * DELETE THIS CONSTANT the moment the first pair is added above. Leaving it in place
     * alongside real entries would let a later emptying of the list pass unnoticed, and the
     * guard test rejects that combination.
     */
    public const INTENTIONALLY_EMPTY_UNTIL = 'Employee Master (Phase 1) — its Actions are the first that will audit a field. audit-trail.spec.md BR-AT13.';

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
