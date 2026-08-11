<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'parent_company_id',
        'status',
    ];

    /**
     * The parent company. Null on AHS, which is the top of the hierarchy.
     *
     * ⚠ This relationship is load-bearing beyond org display: read scope is derived from
     * where an account's employer sits here — employed by the parent reads the whole group,
     * employed by a subsidiary reads that subsidiary only (adr/0004 decision 1). A
     * mis-parented subsidiary silently grants its staff group-wide reads.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_company_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_company_id');
    }

    /**
     * True for AHS. AHS is a parent *and* an operating tenant — it employs its own staff
     * and appears in every company picker. It is not an empty holding row (CLAUDE.md §5).
     */
    public function isParent(): bool
    {
        return $this->parent_company_id === null;
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Roles held *at* this company. A real company reference, not a tenant marker
     * (adr/0003 decision 7).
     */
    public function employeeRoles(): HasMany
    {
        return $this->hasMany(EmployeeRole::class);
    }

    public function policyConfigurations(): HasMany
    {
        return $this->hasMany(PolicyConfiguration::class);
    }
}
