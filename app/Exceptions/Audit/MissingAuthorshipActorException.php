<?php

namespace App\Exceptions\Audit;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A write to a table carrying `created_by` / `updated_by` with nobody to attribute it to —
 * `adr/0009` decision 2.
 *
 * ⚠ THROWN RATHER THAN WRITING NULL, and that refusal is the decision. A silent fallback
 * would leave the columns nullable in practice, and it would produce a mechanism that
 * **appears to enforce something while enforcing nothing** — which is worse than no mechanism,
 * because everybody downstream would then trust the column.
 *
 * `employee_documents.uploaded_by` was removed on the argument that `created_by` already
 * records the uploader. That sentence was false for every row written before this observer
 * existed. It is this exception that makes it true from here on.
 */
class MissingAuthorshipActorException extends RuntimeException
{
    public static function for(Model $model, string $event): self
    {
        return new self(
            'Cannot set authorship on '.$model::class." ({$event}): there is no authenticated "
            .'user and no App\Services\Audit\AuthorshipContext is active. '
            .'Writing NULL is deliberately not an option (adr/0009 decision 2) — a column that '
            .'records nobody is not an audit column. '
            .'Seeders, console commands and the importer must enter AuthorshipContext::run(), '
            .'naming the acting user and stating a reason. Do NOT relax this for console '
            .'contexts: that exempts every background process at once.'
        );
    }
}
