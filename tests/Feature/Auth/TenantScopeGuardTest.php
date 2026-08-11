<?php

use App\Models\Scopes\SharedTenantScope;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

/**
 * The architecture guard required by adr/0005 decision 6.
 *
 * Two scope classes catch the wrong CHOICE — a shared table given TenantScope loses its
 * shared rows, and a reviewer comparing the class name against schema.md will see it. They
 * do not catch the OMISSION: a new model with no scope at all, which reads every company's
 * rows and looks entirely normal doing it.
 *
 * Omission is the likelier error and gets likelier over time. Phase 2's leave, payroll and
 * attendance tables will be written by someone who has adr/0005 available but no reason to
 * open it, because nothing in the act of writing Schema::create prompts them to. A review
 * catches what a reviewer thinks to look for; this catches what nobody thought about.
 */

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

        $scopes = array_keys($model->getGlobalScopes());

        $hasScope = in_array(TenantScope::class, $scopes, true)
            || in_array(SharedTenantScope::class, $scopes, true);

        $isExempt = defined($class.'::TENANT_SCOPE_EXEMPT');

        if (! $hasScope && ! $isExempt) {
            $undeclared[] = $class;
        }
    }

    expect($undeclared)->toBe([], sprintf(
        "These models have a company_id column but declare no tenant scope: %s.\n".
        'Apply TenantScope, or SharedTenantScope if company_id IS NULL means shared, or '.
        'declare a TENANT_SCOPE_EXEMPT constant stating why neither applies '.
        '(adr/0005 decision 6).',
        implode(', ', $undeclared)
    ));
});

it('does not let a model claim both a scope and an exemption', function () {
    $contradictory = [];

    foreach (allEloquentModels() as $class) {
        $model = new $class();
        $scopes = array_keys($model->getGlobalScopes());

        $hasScope = in_array(TenantScope::class, $scopes, true)
            || in_array(SharedTenantScope::class, $scopes, true);

        if ($hasScope && defined($class.'::TENANT_SCOPE_EXEMPT')) {
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

it('exempts only company-reference tables, and only with a stated reason', function () {
    foreach (allEloquentModels() as $class) {
        if (! defined($class.'::TENANT_SCOPE_EXEMPT')) {
            continue;
        }

        expect(constant($class.'::TENANT_SCOPE_EXEMPT'))
            ->toBeString()
            ->not->toBe('');
    }

    // employee_roles is the known case today: company_id answers "in which company does
    // this role apply", not "which tenant owns this row".
    expect(defined(App\Models\EmployeeRole::class.'::TENANT_SCOPE_EXEMPT'))->toBeTrue();
});
