<?php

use App\Observers\AuthorshipObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

/**
 * The architecture guard `adr/0009` decision 1 requires.
 *
 * ⚠ WITHOUT THIS TEST THE OBSERVER IS A TRAIT IN DISGUISE. An observer registered from a list
 * is opt-in exactly like a trait is: a model added to a new migration and left off the list
 * writes NULL — now a hard database error, but only because decision 3 made the columns
 * NOT NULL. Before that it wrote silently, which is the defect the whole ADR exists to close.
 *
 * ⚠ IT CHECKS AGAINST THE LIVE SCHEMA, NEVER AGAINST A WRITTEN LIST. A guard that compared
 * AuthorshipObserver::MODELS with a second copy of the same names would agree with itself
 * forever and prove nothing. `information_schema` is the only party here that cannot be
 * edited to match an assumption.
 *
 * Both directions, as TenantScopeGuardTest does — and for the same reason. Testing only that
 * listed models have the column would pass while a whole table went unobserved.
 */
function authorshipModels(): array
{
    $models = [];

    foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
        $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

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

/** Is the observer actually attached, rather than merely named in the list? */
function hasAuthorshipListener(string $class): bool
{
    $listeners = Model::getEventDispatcher()->getListeners("eloquent.creating: {$class}");

    foreach ($listeners as $listener) {
        // Laravel wraps class-based observers in a closure; the observer's name survives in
        // the bound "class@method" string it closes over, which is what this looks for.
        $bound = (new ReflectionFunction($listener))->getStaticVariables();

        foreach ($bound as $value) {
            if (is_string($value) && str_contains($value, class_basename(AuthorshipObserver::class))) {
                return true;
            }
        }
    }

    return false;
}

it('finds the models it is meant to be guarding', function () {
    // ⚠ conventions.md §9: a guard over a collection must assert the collection is not empty,
    // or it passes forever while iterating nothing.
    expect(authorshipModels())->not->toBeEmpty()
        ->toContain(App\Models\Employee::class);

    expect(AuthorshipObserver::MODELS)->not->toBeEmpty();
});

/**
 * ⚠ DIRECTION (i) — THE ONE THAT MATTERS ON THE DAY SOMEBODY ADDS A TABLE.
 *
 * A new business table carries created_by by conventions.md §3. If its model is left off the
 * observer's list, every insert fails on a NOT NULL violation that names the column and not
 * the cause — and before adr/0009 it would have written NULL and said nothing at all.
 */
it('observes every model whose table carries created_by', function () {
    $unobserved = [];

    foreach (authorshipModels() as $class) {
        $model = new $class();

        if (! Schema::hasColumn($model->getTable(), 'created_by')) {
            continue;
        }

        if (! in_array($class, AuthorshipObserver::MODELS, true)) {
            $unobserved[] = $class;
        }
    }

    expect($unobserved)->toBe([], sprintf(
        "These models have a created_by column but are not in AuthorshipObserver::MODELS: %s.\n".
        'Add them, or the first insert fails on NOT NULL with a message that names the column '.
        'rather than the omission (adr/0009 decision 1).',
        implode(', ', $unobserved)
    ));
});

/**
 * ⚠ DIRECTION (ii) — the list must not outlive the schema.
 *
 * A model listed whose table has no such column means the list has drifted, and a drifted
 * list is one nobody can trust to be complete either.
 */
it('lists no model whose table lacks the columns', function () {
    $spurious = [];

    foreach (AuthorshipObserver::MODELS as $class) {
        $model = new $class();

        if (! Schema::hasColumn($model->getTable(), 'created_by')) {
            $spurious[] = $class;
        }
    }

    expect($spurious)->toBe([], sprintf(
        'These models are in AuthorshipObserver::MODELS but their tables have no created_by: %s.',
        implode(', ', $spurious)
    ));
});

/**
 * ⚠ Listed is not the same as registered. AppServiceProvider::boot() walks the list and calls
 * observe(); if that loop were removed, both tests above would still pass while nothing was
 * observed at all — a guard agreeing with a list rather than with behaviour.
 */
it('actually attaches the observer to every listed model', function () {
    $unattached = [];

    foreach (AuthorshipObserver::MODELS as $class) {
        if (! hasAuthorshipListener($class)) {
            $unattached[] = $class;
        }
    }

    expect($unattached)->toBe([], sprintf(
        'These models are listed but have no AuthorshipObserver creating listener attached: %s. '.
        'Check AppServiceProvider::boot().',
        implode(', ', $unattached)
    ));
});

/**
 * The behavioural half. The three tests above are structural and would all pass against an
 * observer whose methods were empty.
 */
it('writes both columns on insert, not just created_by', function () {
    $actor = App\Models\User::factory()->masterAdmin()->create();

    $this->actingAs($actor);

    $function = App\Models\JobFunction::create(['name' => 'Coverage Probe']);

    // ⚠ Both, because adr/0009 decision 3 makes both NOT NULL — for a row that has never been
    // updated, its last update is its creation.
    expect($function->created_by)->toBe($actor->id)
        ->and($function->updated_by)->toBe($actor->id);
});
