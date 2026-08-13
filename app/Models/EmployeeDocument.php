<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * A DESCRIPTIVE child of the employee — cascades on a company transfer (adr/0003 decision 7).
 *
 * ⚠ `file_path` IS WRITE-ONCE. Replacing a document is a NEW ROW plus a soft delete of the old
 * one, never an edit to the path (schema.md § employee_documents).
 *
 * The prohibition is what keeps `created_by` true, and that is why it is enforced here rather
 * than described in a document. Overwrite the path in place and `created_by` names whoever
 * uploaded a file that is no longer there, while `updated_by` names the person who actually
 * supplied the current one — so the row reads as though the first person uploaded the second
 * person's file. Nothing errors, and the record is wrong in a way that looks ordinary.
 *
 * There is deliberately NO `uploaded_by`: the row is created by the upload, so `created_by`
 * IS the uploader. Two columns naming one actor is the duplication conventions.md §3 rejects.
 */
class EmployeeDocument extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * A starting set, not exhaustive — amendable by a future migration (§10 decision 4).
     *
     * ⚠ OTHER is the one type the EMPLOYEE MAY NOT SEE (employee-master.spec.md §6.3). The
     * other six are already theirs in any real sense: they submitted the identity documents,
     * and the letters are addressed to them.
     */
    public const TYPES = [
        'IC',
        'PASSPORT',
        'EDUCATION_CERTIFICATE',
        'OFFER_LETTER',
        'CONFIRMATION_LETTER',
        'RESIGNATION_LETTER',
        'OTHER',
    ];

    /** The six types an employee may retrieve for themselves (adr/0004 decision 9). */
    public const EMPLOYEE_READABLE_TYPES = [
        'IC',
        'PASSPORT',
        'EDUCATION_CERTIFICATE',
        'OFFER_LETTER',
        'CONFIRMATION_LETTER',
        'RESIGNATION_LETTER',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        // ⚠ The write-once rule, enforced. The row stays editable — `type` may be corrected —
        // but the path to the file may not move, because moving it silently re-attributes the
        // upload. The absence of an edit screen is not a guarantee: an ->update() written
        // anywhere in the codebase would otherwise succeed.
        static::updating(function (self $document): void {
            if ($document->isDirty('file_path')) {
                throw new RuntimeException(
                    'employee_documents.file_path is write-once: replacing a document is a new '.
                    'row plus a soft delete of the old one, never an edit to the path — which '.
                    'is what keeps created_by true as the uploader (schema.md).'
                );
            }
        });
    }

    protected $fillable = [
        'company_id',
        'employee_id',
        'type',
        'file_path',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The uploader. There is no `uploaded_by` column, and this is why none is needed. */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
