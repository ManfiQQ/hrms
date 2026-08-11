<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-company HR policy numbers. Nothing configurable is hardcoded in business logic, even
 * though all six current entities happen to share the same values today — the moment one
 * diverges, code with a literal in it is wrong everywhere (conventions.md §5).
 *
 * The Auth numbers live here too: password minimum length, the four failed-login throttle
 * tiers, the session inactivity window, and the activation validity (adr/0004 decision 6).
 */
class PolicyConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'key',
        'value',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
