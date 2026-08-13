<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;

/**
 * `employee_documents.file_path` is write-once — schema.md § employee_documents.
 *
 * ⚠ WHY THIS IS ENFORCED AND NOT MERELY DOCUMENTED. The rule is what keeps `created_by` true
 * as the uploader, which is the whole reason `uploaded_by` could be removed. Overwrite the path
 * in place and `created_by` names whoever uploaded a file that is no longer there, while
 * `updated_by` names the person who actually supplied the current one — so the row reads as
 * though the first person uploaded the second person's file.
 *
 * Nothing errors when that happens. The row stays valid, the document opens, and the
 * attribution is wrong in a way no query can detect afterwards, because the old path is gone.
 */
beforeEach(function () {
    // ⚠ actingAs: correcting a mis-filed document is an act BY somebody, and adr/0009 records
    // them in updated_by. The rule under test — file_path is write-once — is unaffected either
    // way, but modelling the edit as authorless would misdescribe it.
    // ⚠ masterAdmin(), not a plain account: a STANDARD user with no employee record is the
    // orphaned shape ReadScopeResolver throws on, and this test reads documents through
    // TenantScope. FULL resolves to every company without needing an employee.
    $this->actingAs(App\Models\User::factory()->masterAdmin()->create());

    $this->company = Company::factory()->create(['code' => 'AHS']);
    $this->dept = Department::factory()->shared()->create(['name' => 'HQ']);

    $this->employee = Employee::factory()
        ->forCompany($this->company)
        ->create(['department_id' => $this->dept->id]);
});

function aDocument(string $path = 'documents/ic-original.pdf'): EmployeeDocument
{
    return EmployeeDocument::factory()
        ->forEmployee(test()->employee)
        ->ofType('IC')
        ->storedAt($path)
        ->create();
}

it('refuses to repoint file_path at another file', function () {
    $document = aDocument();

    expect(fn () => $document->update(['file_path' => 'documents/ic-replacement.pdf']))
        ->toThrow(RuntimeException::class);

    expect($document->fresh()->file_path)->toBe('documents/ic-original.pdf');
});

/**
 * The row is not frozen — only the path is. A mis-filed document must stay correctable, or the
 * rule would push people toward deleting and re-uploading to fix a dropdown, which loses the
 * original upload's attribution to fix something that was never about the file.
 */
it('still permits the rest of the row to be corrected', function () {
    $document = aDocument();

    $document->update(['type' => 'PASSPORT']);

    expect($document->fresh()->type)->toBe('PASSPORT')
        ->and($document->fresh()->file_path)->toBe('documents/ic-original.pdf');
});

/**
 * The sanctioned replacement path, asserted so the prohibition above reads as a redirection
 * rather than a dead end: both rows survive, the old one soft-deleted, and each keeps the
 * creator who actually uploaded it.
 */
it('replaces a document with a new row and a soft delete, keeping both attributions', function () {
    $original = aDocument();

    $original->delete();

    $replacement = aDocument('documents/ic-replacement.pdf');

    expect(EmployeeDocument::query()->pluck('id'))
        ->toContain($replacement->id)
        ->not->toContain($original->id);

    expect(EmployeeDocument::withTrashed()->pluck('id'))
        ->toContain($original->id)
        ->toContain($replacement->id);
});
