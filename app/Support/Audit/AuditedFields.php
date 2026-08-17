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
        // ⚠ `position_id`, `department_id` and `level` added by Employee Master slice 2,
        // written by App\Actions\Employee\ChangeEmployeeAssignment. They are audited for the
        // same reason `staff_status` is, and NOT because every column should be: the ledger
        // says what the value WAS on a date, this says who moved it and why.
        //
        // `department_id` earns it twice over — approval routing resolves the HOD stage per
        // (department, company), so moving somebody between departments silently changes who
        // approves their leave.
        //
        // ⚠ `company_id` joined this list on 2026-08-13, when TransferCompany was written.
        // It was withheld until then for a reason the registry enforces in both directions: an
        // entry with no Action behind it fails AuditAuthorshipTest, exactly as an Action
        // auditing an unlisted field does.
        //
        // It is audited because a transfer reassigns statutory responsibility for an
        // employee's EPF, SOCSO and EA Form between two legal entities (§5.7). When a filing
        // is queried later, this row is what shows WHO made that so — the only thing
        // distinguishing an ordinary HR transfer from a Master Admin support intervention.
        //
        // ⚠ `superseded_at` joined on 2026-08-17 with `adr/0015`, written by CreateEmployee when
        // a rejoiner's registration releases the prior record's claim on `ic_no`, `passport_no`
        // and `fingerprint_id`. It is audited because a wrongly-set value FREES AN IDENTITY
        // SILENTLY — nothing errors, and the only trace of who released it is this row.
        \App\Models\Employee::class => ['staff_status', 'employee_no', 'position_id', 'department_id', 'level', 'company_id', 'superseded_at'],

        // ⚠ Account operations, and note what is NOT here: `password` and
        // `activation_token`. audit_logs is readable by HR and ASSISTANT_DIRECTOR within
        // their read scope (BR-AT9), so auditing a credential's VALUE would hand it to every
        // reader of that screen. The derived facts below record that the operation happened,
        // which is the accountable part; the secret is not.
        //
        // ⚠ `superseded_at` joined on 2026-08-17 with `adr/0015`. It sits beside `phone_no` above
        // for a reason: what this column releases IS that column's value as a login username. The
        // Employee half of the same event records that a historical record gave up its identity
        // numbers; this half records that an account gave up a CREDENTIAL, and only this one
        // would show two live accounts becoming able to share one.
        \App\Models\User::class => ['phone_no', 'password_changed_at', 'locked_until', 'activation_expires_at', 'system_access', 'superseded_at'],

        // ⚠ EmployeeDocument IS MISSING ON PURPOSE, AND IT IS COMING — adr/0012 decision 9.
        //
        // Anyone reading this list and asking why documents will be audited while ROLE GRANTS
        // are not is asking about a real contradiction, so the answer lives here rather than
        // only in an ADR. Mirroring employee_roles into audit_logs was refused on 2026-08-13
        // because the pivot already records assigned_by — one fact, two records (adr/0003
        // decision 8). By that argument created_by on a document row is enough.
        //
        // The exception is NAMED rather than a reversal: documents are the only place in this
        // system where the record and the thing it refers to can COME APART. Every other table
        // stores facts; this one stores a pointer to bytes, and a row can say a file exists
        // when it does not — after adr/0012 decision 8's deletion that is designed, not
        // hypothetical. Where a row cannot fully answer for what happened, a second record is
        // not duplication.
        //
        // Role grants stay unaudited, unchanged. This does not generalise: it applies to
        // tables holding files, and there is exactly one.
        //
        // ⚠ NOT ADDED YET, AND THAT IS THIS REGISTRY WORKING. It fails in both directions —
        // an entry here with no Action behind it fails AuditAuthorshipTest exactly as an
        // Action auditing an unlisted field does. The Actions land with the Documents tab
        // (adr/0012 decision 11), and the entry lands with them, in the same PR.
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
