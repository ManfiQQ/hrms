<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `employee_documents.type` gains an eighth value, `PHOTO` — `adr/0013` decision 7.
 *
 * ⚠ A FORWARD MIGRATION. `conventions.md` §11 does not apply: the creating migration
 * (`2026_08_13_100300`) is untouched, and this genuinely changes the column rather than
 * correcting it in place — the same footing as `2026_08_13_100700`, which added the fifth
 * `change_type`. The enum was always described as a starting set that a later migration may
 * extend; this is that migration.
 *
 * ⚠ WHY A DOCUMENT TYPE AND NOT A `photo_path` COLUMN ON `employees`. An employee photo is a
 * FILE, and `adr/0012` already decides everything about how files here are stored, served,
 * replaced, deleted and audited. A path column would be a second file path in the system
 * governed by none of it: no write-once lock, no policy, no audit trail, no defined disk. The
 * write-once rule matters most — replacing a photo is a new row plus a soft delete of the old
 * one, which is what keeps `created_by` true as the person who uploaded the file that is
 * actually there.
 *
 * ⚠ `PHOTO` IS READABLE BY THE EMPLOYEE, joining the six they may already retrieve
 * (`adr/0004` decision 9, EmployeeDocument::EMPLOYEE_READABLE_TYPES). `OTHER` stays the only
 * type withheld from them, which is precisely what gives `OTHER` its defined purpose as the
 * home for internal notes and investigation material.
 */
return new class extends Migration
{
    /**
     * The eight, in the order the column has always listed them plus the new one last.
     *
     * ⚠ APPENDED AFTER `OTHER` RATHER THAN SLOTTED IN BEFORE IT. Reading the list, `PHOTO`
     * belongs beside the other real document types and `OTHER` belongs at the end as the
     * escape hatch — but MySQL orders an enum by declaration, so moving `OTHER` would change
     * the stored ordinal of an existing value for a cosmetic gain. The list is read by name
     * everywhere; nothing reads it by position, and nothing should be made to.
     */
    private const VALUES = [
        'IC',
        'PASSPORT',
        'EDUCATION_CERTIFICATE',
        'OFFER_LETTER',
        'CONFIRMATION_LETTER',
        'RESIGNATION_LETTER',
        'OTHER',
        'PHOTO',
    ];

    private const PREVIOUS = [
        'IC',
        'PASSPORT',
        'EDUCATION_CERTIFICATE',
        'OFFER_LETTER',
        'CONFIRMATION_LETTER',
        'RESIGNATION_LETTER',
        'OTHER',
    ];

    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->enum('type', self::VALUES)->change();
        });
    }

    public function down(): void
    {
        // ⚠ Will fail while any PHOTO row exists, and that is correct rather than
        // inconvenient: narrowing the enum under live rows would truncate them to an empty
        // string, leaving a stored file with no type — and the file itself would stay on the
        // disk, unreferenced and unaudited.
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->enum('type', self::PREVIOUS)->change();
        });
    }
};
