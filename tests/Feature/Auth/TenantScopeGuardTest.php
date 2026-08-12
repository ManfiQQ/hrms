<?php

use App\Models\EmployeeRole;
use App\Models\Scopes\SharedTenantScope;
use App\Models\Scopes\SystemTenantScope;
use App\Models\Scopes\TenantScope;
use App\Models\SecurityEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

/**
 * The architecture guard required by adr/0005 decision 6.
 *
 * The scope classes catch the wrong CHOICE — a shared table given TenantScope loses its
 * shared rows, and a reviewer comparing the class name against schema.md will see it. They
 * do not catch the OMISSION: a new model with no scope at all, which reads every company's
 * rows and looks entirely normal doing it.
 *
 * Omission is the likelier error and gets likelier over time. Phase 2's leave, payroll and
 * attendance tables will be written by someone who has adr/0005 available but no reason to
 * open it, because nothing in the act of writing Schema::create prompts them to. A review
 * catches what a reviewer thinks to look for; this catches what nobody thought about.
 *
 * ⚠ THREE classes, not two, since the decision 6 amendment of 2026-08-12. SystemTenantScope
 * was added for audit_logs, where `company_id IS NULL` means "a system-level event" and both
 * older classes are wrong in opposite directions. A new class must be RECOGNISED here rather
 * than have its model exempted from the test — an exemption would say "this table has no
 * tenant rule", which is the opposite of what a third scope class means.
 */

/** Every declaration this guard accepts as "a scope was chosen". */
const TENANT_SCOPE_CLASSES = [
    TenantScope::class,
    SharedTenantScope::class,
    SystemTenantScope::class,
];

function declaredTenantScope(Model $model): ?string
{
    foreach (array_keys($model->getGlobalScopes()) as $scope) {
        if (in_array($scope, TENANT_SCOPE_CLASSES, true)) {
            return $scope;
        }
    }

    return null;
}

/** @return list<class-string<Model>> */
function allEloquentModels(): array
{
    $models = [];

    foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
        $class = 'App\\Models\\'.str_replace(
            ['/', '.php'],
            ['\\', ''],
            $file->getRelativePathname()
        );

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        $models[] = $class;
    }

    return $models;
}

it('finds the models it is meant to be guarding', function () {
    // A guard that silently discovers nothing passes forever while checking nothing.
    expect(allEloquentModels())->not->toBeEmpty()
        ->toContain(App\Models\Employee::class)
        ->toContain(App\Models\Branch::class);
});

it('requires every model with a company_id column to declare its tenant scope', function () {
    $undeclared = [];

    foreach (allEloquentModels() as $class) {
        $model = new $class();

        if (! Schema::hasColumn($model->getTable(), 'company_id')) {
            continue;
        }

        $isExempt = defined($class.'::TENANT_SCOPE_EXEMPT');

        if (declaredTenantScope($model) === null && ! $isExempt) {
            $undeclared[] = $class;
        }
    }

    expect($undeclared)->toBe([], sprintf(
        "These models have a company_id column but declare no tenant scope: %s.\n".
        'Apply TenantScope; or SharedTenantScope if company_id IS NULL means shared across '.
        'companies; or SystemTenantScope if it means a system-level row only Master Admin '.
        'may read; or declare a TENANT_SCOPE_EXEMPT constant stating why none applies '.
        '(adr/0005 decision 6).',
        implode(', ', $undeclared)
    ));
});

it('does not let a model claim both a scope and an exemption', function () {
    $contradictory = [];

    foreach (allEloquentModels() as $class) {
        if (declaredTenantScope(new $class()) !== null && defined($class.'::TENANT_SCOPE_EXEMPT')) {
            $contradictory[] = $class;
        }
    }

    expect($contradictory)->toBe([]);
});

it('applies the shared scope to exactly the two tables where NULL means shared', function () {
    // Widening this set is an ADR decision, not a code change (adr/0005 decision 3).
    $shared = [];

    foreach (allEloquentModels() as $class) {
        $model = new $class();

        if (in_array(SharedTenantScope::class, array_keys($model->getGlobalScopes()), true)) {
            $shared[] = $model->getTable();
        }
    }

    expect($shared)->toEqualCanonicalizing(['branches', 'departments']);
});

/**
 * ⚠ The same restriction, for the same reason: NULL means something DIFFERENT on this table
 * than on the shared two, and the distinction is the only thing keeping the two classes
 * apart. Widening either set is an ADR decision, not a code change.
 */
it('applies the system scope to exactly the one table where NULL means system-level', function () {
    $system = [];

    foreach (allEloquentModels() as $class) {
        $model = new $class();

        if (in_array(SystemTenantScope::class, array_keys($model->getGlobalScopes()), true)) {
            $system[] = $model->getTable();
        }
    }

    expect($system)->toEqualCanonicalizing(['audit_logs']);
});

it('never lets one model declare two different tenant scopes', function () {
    // Two scopes would AND together into a condition nobody wrote deliberately.
    $multiple = [];

    foreach (allEloquentModels() as $class) {
        $declared = array_values(array_intersect(
            array_keys((new $class())->getGlobalScopes()),
            TENANT_SCOPE_CLASSES
        ));

        if (count($declared) > 1) {
            $multiple[$class] = $declared;
        }
    }

    expect($multiple)->toBe([]);
});

it('accepts an exemption only with a stated reason', function () {
    foreach (allEloquentModels() as $class) {
        if (! defined($class.'::TENANT_SCOPE_EXEMPT')) {
            continue;
        }

        expect(constant($class.'::TENANT_SCOPE_EXEMPT'))
            ->toBeString()
            ->not->toBe('');
    }
});

/**
 * The two exemptions that exist today, asserted by name.
 *
 * A guard that merely accepts *any* exemption drifts into a blanket opt-out. Naming them
 * means adding a third is a visible change to this test rather than a quiet constant on a
 * new model.
 */
it('recognises the declared exemptions and no others', function () {
    $exempt = [];

    foreach (allEloquentModels() as $class) {
        if (defined($class.'::TENANT_SCOPE_EXEMPT')) {
            $exempt[] = $class;
        }
    }

    expect($exempt)->toEqualCanonicalizing([
        // company_id answers "in which company does this role apply", not "which tenant owns
        // this row" (adr/0003 decision 7).
        EmployeeRole::class,

        // Written before authentication, so there may be no account from which to resolve a
        // scope — and in the failed-attempt case, no account at all. This is a DECLARATION,
        // not silence: the whole value of this test is that "deliberately unscoped" and
        // "someone forgot" stay distinguishable (audit-trail.spec.md §3).
        SecurityEvent::class,
    ]);
});
