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

#[Fillable(['name', 'email', 'password'])]
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
