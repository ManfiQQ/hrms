<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A generic gap-free counter — adr/0003 decision 9.
 *
 * ⚠ NO TENANT SCOPE, AND THE TABLE HAS NO company_id. `employee_no` is a single GROUP-WIDE
 * sequence: an AIM employee is `AHS-0042`, not `AIM-0042`, because the unique index on
 * `employees.employee_no` is group-wide and not composite with `company_id`
 * (employee-master.spec.md §10 decision 1). A per-company counter would collide against it.
 *
 * The scope guard test reads the declaration below; this model is exempt by having no
 * `company_id` column at all rather than by opting out.
 *
 * ⚠ Never read this outside SequenceGenerator. A caller that reads `next_value` without the
 * lock has reintroduced MAX() + 1 with extra steps.
 */
class Sequence extends Model
{
    use HasFactory;

    /** The key Employee Master numbers from. */
    public const EMPLOYEE_NO = 'employee_no';

    protected $fillable = [
        'key',
        'next_value',
    ];

    protected function casts(): array
    {
        return [
            'next_value' => 'integer',
        ];
    }
}
