<?php

namespace App\Models;

use App\Exceptions\Employee\RestrictedRoleGrantException;
use App\Models\Scopes\NotRevokedScope;
use App\Services\Auth\RestrictedRoleContext;
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

    /**
     * Deliberately carries neither TenantScope nor SharedTenantScope.
     *
     * `company_id` here is a real reference to *which company the row is about* — not a
     * tenant marker (adr/0003 decision 7). Scoping it to the reader's companies would filter
     * authority records by who is looking, which is a different question from the one the
     * column answers.
     *
     * This constant is what the architecture guard test reads. The exemption must be
     * DECLARED, never expressed by silence, so that "deliberately unscoped" and "someone
     * forgot" stay distinguishable — that distinction is the entire value of the test
     * (adr/0005 decision 6).
     */
    public const TENANT_SCOPE_EXEMPT = 'company_id is a company reference, not a tenant marker (adr/0003 decision 7)';

    /** The six authority roles. STAFF, MASTER_ADMIN and DIRECTOR are deliberately absent. */
    public const ROLES = [
        'ASSISTANT_DIRECTOR',
        'HR',
        'ACCOUNT',
        'HOD',
        'MANAGER',
        'SUPERVISOR',
    ];

    /**
     * The four only Master Admin may grant — BR-16, `adr/0003` decision 3.
     *
     *   ACCOUNT             the only door to salary data
     *   HR                  can create further HR; self-propagating
     *   ASSISTANT_DIRECTOR  top of the approval chain
     *   HOD                 granting it does not add an approval tier, it BYPASSES TWO
     *
     * ⚠ ALL FOUR ARE HARDCODED, and there is no `is_restricted` column anywhere. `adr/0003`
     * decision 3 described HOD as restricted-but-Master-Admin-changeable; that configurability
     * is **deferred, not omitted**, and reopening it needs an ADR
     * (`employee-master.spec.md` BR-16).
     *
     * MANAGER and SUPERVISOR are deliberately absent: routine appointments that change often,
     * spanning one approval stage and no sensitive data. Routing them through Master Admin
     * would pull that account into daily HR work.
     */
    public const RESTRICTED = [
        'ACCOUNT',
        'HR',
        'ASSISTANT_DIRECTOR',
        'HOD',
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

        // ⚠ BR-16 ENFORCED HERE, ON THE MODEL, AND NOT ONLY IN A POLICY.
        //
        // A policy protects the paths that remember to ask it. This fires on every write path
        // that exists or will ever exist — an Action, a controller, a seeder, the legacy
        // importer, a future module's service, a stray EmployeeRole::create() in a queue job.
        //
        // The stake is adr/0003 decision 5: an HR who can insert a row here grants themselves
        // ACCOUNT and reads every salary in the group. The rule would not be violated, it
        // would be walked around through the front door, and it would look like ordinary HR
        // activity in the audit log.
        static::creating(function (self $role): void {
            $role->assertGrantPermitted($role->role);
        });

        // The same door, one step sideways. Re-granting a revoked role inserts a NEW row
        // (§5.6), so `creating` covers the ordinary path — but an ->update() that rewrites
        // `role` on an existing row would reach ACCOUNT without ever creating anything.
        static::updating(function (self $role): void {
            if ($role->isDirty('role')) {
                $role->assertGrantPermitted($role->role);
            }
        });
    }

    /**
     * @throws RestrictedRoleGrantException
     */
    protected function assertGrantPermitted(string $role): void
    {
        if (! in_array($role, self::RESTRICTED, true)) {
            return;
        }

        // The deliberate escape hatch, entered on purpose and carrying a reason. Seeders,
        // console commands and the importer have no authenticated user; this is how they say
        // so out loud instead of a runningInConsole() check quietly exempting all of them
        // forever (see RestrictedRoleContext).
        if (app(RestrictedRoleContext::class)->isActive()) {
            return;
        }

        $actor = auth()->user();

        // ⚠ Null is REFUSED, not waved through. If absence of a user meant "allowed", then
        // every queue job, console command and tinker session would be a shortcut past
        // BR-16 that nobody chose to open.
        if ($actor === null) {
            throw RestrictedRoleGrantException::withoutActor($role);
        }

        if (! $actor->isMasterAdmin()) {
            throw RestrictedRoleGrantException::byAccount($role);
        }
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
