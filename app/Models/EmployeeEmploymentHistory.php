<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Employment held BEFORE joining this group — a DESCRIPTIVE child that cascades on a company
 * transfer (adr/0003 decision 7).
 *
 * ⚠ NOT a record of movement between group entities. That is `employee_status_history`, an
 * EVENT table whose `company_id` is frozen forever, plus `employees.company_id` changing in
 * place. The two tables sound alike and behave in opposite directions on a transfer.
 *
 * `company_name` and `position` are strings, not foreign keys — the employer and the job title
 * belong to another company, and importing either into this system's own vocabulary would
 * make the outside employment unrecordable and pollute the org chart.
 */
class EmployeeEmploymentHistory extends Model
{
    use HasFactory, SoftDeletes;

    /** Singular-to-plural inference would give `employee_employment_histories`. */
    protected $table = 'employee_employment_history';

    /** Narrows every query to the account's read scope (adr/0005 decision 2). */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'company_id',
        'employee_id',
        'company_name',
        'position',
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The tenant marker's company — this group's entity, NOT the former employer. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
