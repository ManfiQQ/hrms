<?php

namespace App\Models;

use App\Models\Scopes\SystemTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * What changed, who changed it, and when — one row per changed field
 * (audit-trail.spec.md BR-AT2).
 *
 * Authentication events do NOT live here; they are SecurityEvent (BR-AT1). A failed login has
 * no old_value and never will, and one table holding both would need a rule about which
 * columns are meaningful for which event type — a rule that would live nowhere.
 *
 * ⚠ This model does not write itself. AuditLogger (audit-trail.spec.md §5.1) is the only
 * permitted writer, and it does not exist yet: it must generate the batch_id once per
 * transaction, reject a write outside a transaction, and resolve labels at write time. A
 * direct AuditLog::create() bypasses all three and is a review failure.
 */
class AuditLog extends Model
{
    use HasFactory;

    /**
     * Append-only: created_at alone, managed by Eloquent; updated_at does not exist.
     *
     * The column also carries DEFAULT CURRENT_TIMESTAMP in the migration, so even a raw
     * insert lands with a timestamp.
     */
    public const UPDATED_AT = null;

    /**
     * ⚠ Shows `company_id IS NULL` rows to Master Admin ALONE, and narrows everything else to
     * the account's read scope (adr/0005 decision 6, amendment note).
     *
     * NULL here means "a system-level event" — attributable to no company. TenantScope would
     * hide those rows from Master Admin, whose own actions they mostly are; SharedTenantScope
     * would show them to a subsidiary-employed HR. Both are wrong, in opposite directions,
     * which is why a third class exists rather than a flag on an existing one.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new SystemTenantScope());

        // BR-AT6: append-only, with no exception and none for Master Admin. A correction is
        // a new row. The value of an audit trail comes from not being able to DELETE it, not
        // from not being able to SEE it — which is what makes BR-AT9's read permissions safe
        // to grant to HR in the first place.
        //
        // Enforced on the model as well as by the absence of a UI path, because the absence
        // of a path is not a guarantee: an ->update() or ->delete() anywhere in the codebase
        // would otherwise succeed silently.
        static::updating(function (): never {
            throw new RuntimeException('audit_logs is append-only: a correction is a new row (audit-trail.spec.md BR-AT6).');
        });

        static::deleting(function (): never {
            throw new RuntimeException('audit_logs is append-only and is kept forever: no row may be deleted (audit-trail.spec.md BR-AT6, BR-AT11).');
        });
    }

    protected $fillable = [
        'batch_id',
        'company_id',
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'field',
        'old_value',
        'new_value',
        'old_label',
        'new_label',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * The subject — polymorphic, not an employee (BR-AT3).
     *
     * Three of this table's writers have no employee to point at: a system_access change
     * (subject: a users row), an attendance correction (an attendance_import_rows row), and a
     * salary adjustment (a salary-ledger row).
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The actor. Nullable for console and system-initiated writes. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Null on a system-level event — see the scope note above. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** True when this row records a system-level event, attributable to no company. */
    public function isSystemLevel(): bool
    {
        return $this->company_id === null;
    }
}
