<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducationHistory;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeFamilyMember;
use App\Models\EmployeeJobFunction;
use App\Models\JobFunction;
use App\Models\User;

/**
 * Tenant scope on every table Employee Master slice 1 creates — one assertion per table, and
 * the six do NOT all assert the same thing.
 *
 * ⚠ THE THREE CASCADE CATEGORIES ARE THREE DIFFERENT SCOPE ANSWERS, and a test suite that
 * asserted "scoped" six times would be wrong four ways round. schema.md § Company transfer:
 *
 *   descriptive       → TenantScope, narrowed to the reader's companies
 *   company-reference → exempt; company_id says which company the row is ABOUT
 *   (job_functions)   → no company_id at all; one group-wide vocabulary
 *
 * Every failure here returns a wrong ANSWER rather than an error — either rows that should be
 * invisible, or rows that vanish with nothing to notice.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->tursenia = Company::factory()->subsidiary($this->ahs)->create(['code' => 'TURSENIA']);

    $this->dept = Department::factory()->shared()->create(['name' => 'HQ Marketing']);
});

function childEmployeeAt(Company $company): Employee
{
    return Employee::factory()
        ->forCompany($company)
        ->create(['department_id' => test()->dept->id]);
}

function childAccountAt(Company $company): User
{
    return User::factory()->forEmployee(childEmployeeAt($company))->create();
}

it('narrows family members to the account read scope', function () {
    $mine = EmployeeFamilyMember::factory()
        ->forEmployee(childEmployeeAt($this->aim))->create();

    $theirs = EmployeeFamilyMember::factory()
        ->forEmployee(childEmployeeAt($this->tursenia))->create();

    $this->actingAs(childAccountAt($this->aim));

    expect(EmployeeFamilyMember::query()->pluck('id'))
        ->toContain($mine->id)
        ->not->toContain($theirs->id);
});

it('narrows education history to the account read scope', function () {
    $mine = EmployeeEducationHistory::factory()
        ->forEmployee(childEmployeeAt($this->aim))->create();

    $theirs = EmployeeEducationHistory::factory()
        ->forEmployee(childEmployeeAt($this->tursenia))->create();

    $this->actingAs(childAccountAt($this->aim));

    expect(EmployeeEducationHistory::query()->pluck('id'))
        ->toContain($mine->id)
        ->not->toContain($theirs->id);
});

it('narrows previous employment history to the account read scope', function () {
    $mine = EmployeeEmploymentHistory::factory()
        ->forEmployee(childEmployeeAt($this->aim))->create();

    $theirs = EmployeeEmploymentHistory::factory()
        ->forEmployee(childEmployeeAt($this->tursenia))->create();

    $this->actingAs(childAccountAt($this->aim));

    expect(EmployeeEmploymentHistory::query()->pluck('id'))
        ->toContain($mine->id)
        ->not->toContain($theirs->id);
});

/**
 * ⚠ The highest-consequence row of the four. These are IC scans, passports and letters — the
 * documents CLAUDE.md §3 requires encrypted off-site backups for. A scope failure here leaks
 * identity documents across tenants, and it leaks them silently.
 */
it('narrows documents to the account read scope', function () {
    $mine = EmployeeDocument::factory()
        ->forEmployee(childEmployeeAt($this->aim))->create();

    $theirs = EmployeeDocument::factory()
        ->forEmployee(childEmployeeAt($this->tursenia))->create();

    $this->actingAs(childAccountAt($this->aim));

    expect(EmployeeDocument::query()->pluck('id'))
        ->toContain($mine->id)
        ->not->toContain($theirs->id);
});

/**
 * ⚠ THE INVERSE ASSERTION, and the one most likely to be got wrong by "fixing" the model.
 *
 * `job_functions` has no `company_id` because the vocabulary is group-wide. Someone applying
 * TenantScope for consistency would not produce an error — they would produce an empty picker
 * for every subsidiary, and six companies would quietly start inventing their own spellings,
 * which is the drift CLAUDE.md §5 exists to prevent.
 */
it('keeps the job function vocabulary visible to every company', function () {
    $function = JobFunction::factory()->named('Live Host')->create();

    $this->actingAs(childAccountAt($this->tursenia));

    expect(JobFunction::query()->pluck('id'))->toContain($function->id);
});

/**
 * ⚠ THE COMPANY-REFERENCE ASSERTION. `employee_job_functions.company_id` says which company
 * the row is ABOUT, not which tenant owns it, so it is deliberately not narrowed by the
 * reader — the same declaration `EmployeeRole` carries.
 *
 * The assignment is deliberately created at TURSENIA for an employee AIM employs: the two
 * columns must disagree, or the test cannot tell a company reference from a tenant marker.
 * Applying TenantScope here would hide exactly the cross-company line
 * employee-master.spec.md BR-12 renders on the Employment tab, by returning fewer rows.
 */
it('does not narrow job function assignments by the reader, because company_id is a reference', function () {
    $atTursenia = EmployeeJobFunction::factory()
        ->forEmployee(childEmployeeAt($this->aim))
        ->atCompany($this->tursenia)
        ->create();

    $this->actingAs(childAccountAt($this->aim));

    expect(EmployeeJobFunction::query()->pluck('id'))->toContain($atTursenia->id);
});
