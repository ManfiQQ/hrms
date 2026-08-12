<?php

use App\Support\Audit\AuditedFields;
use Symfony\Component\Finder\Finder;

/**
 * The architecture guard required by BR-AT13, and an honest account of what it is worth.
 *
 * Every Action calls AuditLogger explicitly — no trait, no observer, no saved hook. An
 * observer knows WHAT changed but not WHY, and `reason` is much of why audit_logs is worth
 * keeping. The cost of that choice is that an Action can simply forget, and this test is
 * what narrows the gap.
 *
 * ✅ IT CATCHES: a field in the registry with no Action behind it — the spec growing while
 *    the code does not, which is the realistic Phase 2 failure.
 *
 * ❌ IT DOES NOT CATCH: an Action that declares AUDITS correctly and then never calls the
 *    logger. The declaration is a promise; nothing here verifies it was kept, and a static
 *    test cannot — the call happens at runtime, inside a branch. What closes that gap is a
 *    per-Action feature test asserting the rows appear, owned by the module that owns the
 *    Action.
 *
 * That limitation is written down rather than left implied. A guard that looks stronger than
 * it is, is worse than a weaker one honestly labelled: it stops people looking for the check
 * that is actually missing.
 */

/** @return list<class-string> */
function allActionClasses(): array
{
    $path = app_path('Actions');

    if (! is_dir($path)) {
        return [];
    }

    $classes = [];

    foreach (Finder::create()->files()->in($path)->name('*.php') as $file) {
        $class = 'App\\Actions\\'.str_replace(
            ['/', '.php'],
            ['\\', ''],
            $file->getRelativePathname()
        );

        if (class_exists($class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/**
 * Every (model, field) pair claimed by an Action, via its AUDITS constant.
 *
 * @return list<array{model: string, field: string, action: class-string}>
 */
function declaredAuditPairs(): array
{
    $declared = [];

    foreach (allActionClasses() as $class) {
        if (! defined($class.'::AUDITS')) {
            continue;
        }

        foreach (constant($class.'::AUDITS') as $model => $fields) {
            foreach ($fields as $field) {
                $declared[] = ['model' => $model, 'field' => $field, 'action' => $class];
            }
        }
    }

    return $declared;
}

/**
 * ⚠ The guard against a guard that checks nothing.
 *
 * An architecture test over an empty set passes forever. This one refuses to: an empty
 * registry is only acceptable while it says so out loud, with an expiry.
 */
it('refuses to pass on an empty registry unless the emptiness is declared', function () {
    if (! AuditedFields::isEmpty()) {
        expect(defined(AuditedFields::class.'::INTENTIONALLY_EMPTY_UNTIL'))->toBeFalse(
            'AuditedFields has entries, so INTENTIONALLY_EMPTY_UNTIL must be deleted — '.
            'leaving it would let a later emptying of the list pass unnoticed.'
        );

        return;
    }

    expect(defined(AuditedFields::class.'::INTENTIONALLY_EMPTY_UNTIL'))->toBeTrue(
        'AuditedFields is empty and nothing says why. An empty registry makes every '.
        'assertion below vacuous. Either add the fields your module audits, or declare '.
        'INTENTIONALLY_EMPTY_UNTIL saying which module will fill it (BR-AT13).'
    );

    expect(constant(AuditedFields::class.'::INTENTIONALLY_EMPTY_UNTIL'))
        ->toBeString()
        ->not->toBe('');
});

it('has an Action declaring every field the registry says must be audited', function () {
    $declared = declaredAuditPairs();
    $unclaimed = [];

    foreach (AuditedFields::pairs() as $pair) {
        $isClaimed = collect($declared)->contains(
            fn (array $d) => $d['model'] === $pair['model'] && $d['field'] === $pair['field']
        );

        if (! $isClaimed) {
            $unclaimed[] = $pair['model'].'.'.$pair['field'];
        }
    }

    expect($unclaimed)->toBe([], sprintf(
        "These fields are in AuditedFields but no Action declares them: %s.\n".
        'Add an AUDITS constant to the Action that changes the field, naming the pairs it '.
        'is responsible for — and then actually call AuditLogger, which this test cannot '.
        'check for you (BR-AT13).',
        implode(', ', $unclaimed)
    ));
});

/**
 * The same drift in the other direction. An Action auditing something nobody wrote down
 * means the registry is no longer the canonical list, which is the whole basis for the specs
 * referencing it instead of restating it.
 */
it('has no Action auditing a field the registry does not list', function () {
    $registry = AuditedFields::pairs();
    $undeclared = [];

    foreach (declaredAuditPairs() as $d) {
        $isListed = collect($registry)->contains(
            fn (array $p) => $p['model'] === $d['model'] && $p['field'] === $d['field']
        );

        if (! $isListed) {
            $undeclared[] = $d['action'].' → '.$d['model'].'.'.$d['field'];
        }
    }

    expect($undeclared)->toBe([], sprintf(
        "These Actions audit fields absent from AuditedFields: %s.\n".
        'The registry is the canonical list (BR-AT13) — add the pair there, and say why in '.
        "the owning module's spec.",
        implode(', ', $undeclared)
    ));
});

it('accepts only real model classes and non-empty field lists in the registry', function () {
    // Asserted unconditionally so this test is never "risky" — a body whose only assertions
    // sit inside a foreach over an empty array performs none at all, which is the same
    // silent-no-op problem the empty-registry guard above exists to prevent.
    expect(AuditedFields::FIELDS)->toBeArray();

    foreach (AuditedFields::FIELDS as $model => $fields) {
        expect(class_exists($model))->toBeTrue("AuditedFields names a missing class: {$model}.")
            ->and($fields)->not->toBeEmpty("AuditedFields lists {$model} with no fields.");
    }
});
