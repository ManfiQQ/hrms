<?php

namespace App\Models;

use App\Models\Scopes\SharedTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ⚠ `company_id` is nullable and NULL means SHARED — same carve-out as Branch
 * (adr/0002 decision 1). HQ Marketing is staffed from several companies.
 *
 * A shared department is a shared PLACE, not a shared approval pool. An HOD approves only
 * for employees sharing their own `employees.company_id`, inside a shared department as
 * much as anywhere else (adr/0002 decision 4). Do not infer authority scope from structure
 * scope — that inference is the error corrected on 2026-08-08.
 */
class Department extends Model
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
        'branch_id',
        'name',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** True when this department is shared across all companies rather than dedicated to one. */
    public function isShared(): bool
    {
        return $this->company_id === null;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
