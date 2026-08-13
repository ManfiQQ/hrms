<?php

use App\Actions\Employee\AssignJobFunction;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeJobFunction;
use App\Models\JobFunction;
use App\Models\User;
use Database\Seeders\JobFunctionSeeder;

/**
 * AssignJobFunction and JobFunctionSeeder — BR-15, `adr/0003` decision 2.
 *
 * ⚠ JOB FUNCTION IS NOT AUTHORITY. They are separate structures because merging them would
 * force the approval engine to answer questions that should not exist — *"can a Live Host
 * approve a leave request?"* Nothing here touches `employee_roles`.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->dept = Department::factory()->shared()->create(['name' => 'HQ']);

    $this->worker = Employee::factory()
        ->forCompany($this->ahs)
        ->create(['department_id' => $this->dept->id]);

    $this->actingAs(User::factory()->forEmployee(
        Employee::factory()->forCompany($this->ahs)->create(['department_id' => $this->dept->id])
    )->create());

    $this->assign = app(AssignJobFunction::class);

    // ⚠ JobFunctionSeeder names the installing Master Admin as the author of its rows
    // (adr/0009 decision 2), so one must exist before it runs — DatabaseSeeder orders
    // MasterAdminSeeder first for the same reason.
    User::factory()->masterAdmin()->create();
});

it('records the function, the company and who assigned it', function () {
    $media = JobFunction::factory()->named('Media')->create();

    $row = $this->assign->execute($this->worker, $media, $this->aim);

    expect($row->job_function_id)->toBe($media->id)
        ->and($row->company_id)->toBe($this->aim->id)
        ->and($row->created_by)->toBe(auth()->id());
});

/**
 * ⚠ BR-12's "Also serving at" line, in data. `employee_job_functions.company_id` is a company
 * REFERENCE — where the person performs the function — and it need not be their payroll
 * employer. An implementation that forced them equal would make the cross-company line
 * unrepresentable.
 */
it('assigns a function at a company that does not employ the person', function () {
    $row = $this->assign->execute($this->worker, JobFunction::factory()->create(), $this->aim);

    expect($this->worker->company_id)->toBe($this->ahs->id)
        ->and($row->company_id)->toBe($this->aim->id);
});

/**
 * ⚠ Deactivation IS the soft delete (schema.md). A deactivated function disappears from the
 * assignment picker — and hiding it in the UI while accepting it on submit would make the
 * withdrawal presentational, the same failure BR-16 guards against for roles.
 */
it('refuses to assign a deactivated function', function () {
    $withdrawn = JobFunction::factory()->named('Live Host')->deactivated()->create();

    expect(fn () => $this->assign->execute($this->worker, $withdrawn, $this->aim))
        ->toThrow(InvalidArgumentException::class);

    expect(EmployeeJobFunction::query()->count())->toBe(0);
});

/**
 * The other half of BR-15's soft-delete rule: withdrawing a function must not orphan or hide
 * the assignments people already hold, or the history breaks.
 */
it('keeps existing assignments readable after the function is deactivated', function () {
    $media = JobFunction::factory()->named('Media')->create();
    $row = $this->assign->execute($this->worker, $media, $this->aim);

    $media->delete();

    expect(EmployeeJobFunction::query()->whereKey($row->id)->exists())->toBeTrue()
        ->and(JobFunction::withTrashed()->whereKey($media->id)->value('name'))->toBe('Media');
});

it('refuses a duplicate assignment of the same function at the same company', function () {
    $media = JobFunction::factory()->named('Media')->create();

    $this->assign->execute($this->worker, $media, $this->aim);

    expect(fn () => $this->assign->execute($this->worker, $media, $this->aim))
        ->toThrow(InvalidArgumentException::class);

    expect(EmployeeJobFunction::query()->count())->toBe(1);
});

it('seeds the five starting functions', function () {
    (new JobFunctionSeeder())->run();

    expect(JobFunction::query()->pluck('name')->all())
        ->toEqualCanonicalizing(['BDO', 'Admin', 'Media', 'Live Host', 'Operation Crew']);
});

/**
 * ⚠ Neither is a job function, and seeding either would create a second way to express
 * something the schema already expresses once (BR-15).
 *
 *   Intern    is an employment_type.
 *   Logistic  is a BRANCH (adr/0002).
 */
it('seeds neither Intern nor Logistic', function () {
    (new JobFunctionSeeder())->run();

    expect(JobFunction::withTrashed()->pluck('name'))
        ->not->toContain('Intern')
        ->not->toContain('Logistic');
});

it('is idempotent', function () {
    (new JobFunctionSeeder())->run();
    (new JobFunctionSeeder())->run();

    expect(JobFunction::withTrashed()->count())->toBe(5);
});

/**
 * ⚠ THE FAILURE A NAIVE `updateOrCreate` WOULD CAUSE. Re-seeding must not undo a Master
 * Admin's decision to withdraw a function — that decision is a soft-deleted row, not debris,
 * and a seeder that restored it would silently put a closed workplace back in the picker.
 */
it('does not resurrect a function that has been deactivated', function () {
    (new JobFunctionSeeder())->run();

    JobFunction::query()->where('name', 'Live Host')->delete();

    (new JobFunctionSeeder())->run();

    expect(JobFunction::query()->where('name', 'Live Host')->exists())->toBeFalse()
        ->and(JobFunction::withTrashed()->count())->toBe(5);
});
