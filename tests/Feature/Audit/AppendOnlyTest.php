<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\SecurityEvent;
use Illuminate\Support\Str;

/**
 * BR-AT6 — both audit tables are append-only, with no exception and none for Master Admin.
 *
 * This is what makes BR-AT9's read permissions safe to grant: an HR who can read the log
 * cannot alter what it says about them. The value of an audit trail comes from not being able
 * to DELETE it, not from not being able to SEE it — which is why "block HR from the log
 * entirely" was rejected (audit-trail.spec.md §10 decision 6).
 *
 * The absence of a UI path is not a guarantee. These tests assert the model itself refuses,
 * so an ->update() or ->delete() written anywhere in the codebase fails loudly instead of
 * succeeding silently.
 *
 * ⚠ Not asserted here: that the WRITE path enforces the transaction rules (BR-AT7, BR-AT8).
 * AuditLogger and SecurityEventLogger do not exist yet — spec §8 tests 8-12 arrive with them.
 */
function anAuditLog(): AuditLog
{
    return AuditLog::create([
        'batch_id' => (string) Str::uuid(),
        'company_id' => null,
        'action' => 'master_admin.scope_bypass',
        'auditable_type' => Employee::class,
        'auditable_id' => 1,
        'field' => 'company_id',
        'old_value' => '2',
        'new_value' => '3',
    ]);
}

function aSecurityEvent(): SecurityEvent
{
    return SecurityEvent::create([
        'user_id' => null,
        'event_type' => 'LOGIN_FAILED',
        'identifier' => '0123456789',
        'ip_address' => '203.0.113.7',
        'user_agent' => 'curl/8.5.0',
    ]);
}

it('refuses to update an audit log row', function () {
    $row = anAuditLog();

    expect(fn () => $row->update(['new_value' => '4']))->toThrow(RuntimeException::class);
});

it('refuses to delete an audit log row', function () {
    expect(fn () => anAuditLog()->delete())->toThrow(RuntimeException::class);
});

it('refuses to update a security event row', function () {
    $row = aSecurityEvent();

    expect(fn () => $row->update(['ip_address' => '198.51.100.1']))
        ->toThrow(RuntimeException::class);
});

it('refuses to delete a security event row', function () {
    // The BR-AT11 retention sweep is a scheduled command with one fixed predicate — it does
    // not run through this model, and it is the only thing that ever removes a row.
    expect(fn () => aSecurityEvent()->delete())->toThrow(RuntimeException::class);
});

it('manages created_at and has no updated_at on either table', function () {
    expect(anAuditLog()->created_at)->not->toBeNull()
        ->and(AuditLog::UPDATED_AT)->toBeNull()
        ->and(aSecurityEvent()->created_at)->not->toBeNull()
        ->and(SecurityEvent::UPDATED_AT)->toBeNull();
});
