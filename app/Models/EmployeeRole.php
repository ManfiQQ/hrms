<?php

namespace App\Models;

use App\Models\Scopes\NotRevokedScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The authority pivot. Authority is a triple: who, at which company, what role.
 *
 * There is no soft delete and no is_enabled flag here, and neither may be added. Revocation
 * is `revoked_date` alone — a second mechanism meaning "revoked" would force every authority
 * check to test two conditions, and the check that tests only one is a silent security hole
 * (adr/0003 decisions 1 and 3, conventions.md §3).
 */
class EmployeeRole extends Model
{
    use HasFactory;

    /** The six authority roles. STAFF, MASTER_ADMIN and DIRECTOR are deliberately absent. */
    public const ROLES = [
        'ASSISTANT_DIRECTOR',
        'HR',
        'ACCOUNT',
        'HOD',
        'MANAGER',
        'SUPERVISOR',
    ];

    protected $fillable = [
        'employee_id',
        'company_id',
        'role',
        'effective_date',
        'assigned_by',
        'revoked_date',
        'revoked_by',
        'revoke_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'revoked_date' => 'date',
        ];
    }

    /**
     * Every query on this model filters `revoked_date IS NULL` by default.
     *
     * This is the whole point of the scope living here: adr/0003 decision 1 calls omitting
     * that filter a silent security failure rather than an error, because it returns revoked
     * authority as current. Applying it by default means the safe query is the one you get
     * without thinking about it.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new NotRevokedScope());
    }

    /**
     * Include revoked rows. Use deliberately.
     *
     * Role history is a real requirement — the UI merges this table and
     * employee_status_history into one chronological timeline (adr/0003 decision 8), and
     * that cannot be built from currently-held rows alone. This is the supported way to
     * read them; never by removing the global scope ad hoc.
     */
    public function scopeWithRevoked(Builder $query): Builder
    {
        return $query->withoutGlobalScope(NotRevokedScope::class);
    }

    /** Only rows that have been revoked. Implies withRevoked(). */
    public function scopeOnlyRevoked(Builder $query): Builder
    {
        return $query->withoutGlobalScope(NotRevokedScope::class)->whereNotNull('revoked_date');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The company this role applies IN — a real company reference, not a tenant marker.
     * That distinction is why these rows are never cascaded on a company transfer: a
     * Manager role at AIM is still a Manager role at AIM after the person's payroll moves
     * (adr/0003 decision 7).
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_date !== null;
    }
}
