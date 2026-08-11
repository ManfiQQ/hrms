<?php

namespace App\Models;

use App\Models\Scopes\SharedTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ⚠ `company_id` is nullable and NULL means SHARED — group-level, available across all
 * companies (HQ, Marketing, Logistics) — not missing data. A set value means
 * company-dedicated (adr/0002 decision 1).
 *
 * The tenant global scope for this model must resolve to
 * `company_id IS NULL OR company_id = :current_company`. A plain equality check silently
 * hides every shared branch: fewer rows, no error. That scope is NOT written yet — it
 * belongs to the Auth & RBAC layer (auth-rbac.spec.md §5.3).
 */
class Branch extends Model
{
    use HasFactory;

    /**
     * Keeps rows where company_id IS NULL — those are SHARED, not missing
     * (adr/0002 decision 1, adr/0005 decision 3).
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new SharedTenantScope());
    }

    protected $fillable = [
        'company_id',
        'name',
        'address',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** True when this branch is shared across all companies rather than dedicated to one. */
    public function isShared(): bool
    {
        return $this->company_id === null;
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
