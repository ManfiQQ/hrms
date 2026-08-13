<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A DESCRIPTIVE child of the employee — cascades on a company transfer (adr/0003 decision 7).
 *
 * ⚠ `level` here is ACADEMIC (SPM, Diploma, Degree). `employees.level` is an org seniority
 * tier and an enum. Same word, unrelated columns — do not join, compare or copy between them.
 */
class EmployeeEducationHistory extends Model
{
    use HasFactory, SoftDeletes;

    /** Singular-to-plural inference would give `employee_education_histories`. */
    protected $table = 'employee_education_history';

    /** Narrows every query to the account's read scope (adr/0005 decision 2). */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'company_id',
        'employee_id',
        'level',
        'institution',
        'year',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
