<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'phone_no', 'email', 'password'])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'activation_expires_at' => 'datetime',
            'activation_downloaded_at' => 'datetime',
            'activation_used_at' => 'datetime',
            'must_change_password' => 'boolean',

            // BR-A3's throttle state. locked_until null does NOT mean "not locked" — it
            // means no TIMED lock is in force; the permanent lock is the counter having
            // reached the fourth tier. Both are read through LoginThrottle, never by hand.
            'locked_until' => 'datetime',
            'failed_login_attempts' => 'integer',

            // adr/0015 decision 2 — this account has released its claim on `phone_no`, because a
            // later account belonging to the same person now holds that number as its username.
            //
            // ⚠ NOT A SOFT DELETE. This table has none and is not gaining one: the row stays,
            // stays queryable, and stays attached to the audit trail its freeze exists to keep
            // (BR-A15, BR-A17). ⚠ NOT AN AUTHENTICATION CHECK EITHER — a superseded account was
            // already frozen and expired by AccountExpiry, and nothing in the login path reads
            // this. Gating login on it would put a second answer beside one that already exists.
            //
            // ⚠ ABSENT FROM THE #[Fillable] LIST ABOVE, and that absence is the enforcement.
            // adr/0015 decision 4: one Action, one path. A wrongly-set value releases a USERNAME
            // silently — nothing errors, and two live accounts simply become able to share one.
            'superseded_at' => 'datetime',
        ];
    }

    /**
     * The employee this account belongs to.
     *
     * NULL for Master Admin and Director, which have no employee record and never will
     * (adr/0001 decision 4, adr/0004 decision 4). ReadScopeResolver reads this to find the
     * employer whose position in the company hierarchy determines read scope.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Master Admin: `system_access = FULL` with a null `employee_id`.
     *
     * This is the single mechanism that identifies one — there is no `is_master_admin`
     * column, and none may be added (adr/0004 decision 2).
     */
    public function isMasterAdmin(): bool
    {
        return $this->system_access === 'FULL';
    }
}
