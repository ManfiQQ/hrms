<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

/**
 * The one place a field is tested for salariness (audit-trail.spec.md §5.4, BR-AT10).
 *
 * ⚠ NO OTHER CLASS MAY MAKE THIS JUDGEMENT. It is the same shape as
 * RoleChecker::canReadSalary(), and for the same reason: a rule repeated per caller is a
 * rule one caller will get wrong, silently. AuditLogReader applies it; nothing else asks.
 *
 * ⚠ WHY THIS MATTERS MORE THAN IT LOOKS. The audit log is the easiest back door in the
 * system to overlook, because it is the one table that writes every value in the database
 * down a second time. A row reading
 *
 *     salary_ledger #482 · basic_salary · 3,200.00 → 3,800.00 · by Aminah · 3 Mar
 *
 * discloses the salary as completely as the payroll screen does, from a module whose
 * permission table says nothing about money. adr/0003 decision 5 is unconditional: no HR
 * reads salary, at any scope.
 *
 * Models declare their own salary-bearing fields:
 *
 *     public const SALARY_FIELDS = ['basic_salary', 'allowance_amount'];
 *
 * A model over a money-bearing table that declares nothing fails the architecture test.
 * Deny-by-default is not available here — the reader cannot know a column is money by
 * looking at an audit row — so the declaration is what stands between HR and every salary
 * change in the group.
 */
class SalaryFields
{
    /**
     * ⚠ Why no model declares anything yet, and until when.
     *
     * Payroll is Phase 2 and no table carries a money column, so the architecture test would
     * check nothing — the kind of test nobody notices has died. It therefore FAILS on an
     * empty set unless this constant is present, which makes "not started yet" a deliberate
     * statement with an expiry rather than a silent gap.
     *
     * DELETE THIS CONSTANT the moment the first model declares SALARY_FIELDS with entries.
     * The guard test rejects it surviving alongside a real declaration, so a later emptying
     * of the set cannot pass unnoticed.
     */
    public const NO_MONEY_TABLES_UNTIL = 'Payroll (Phase 2) — the first module to store a money column. audit-trail.spec.md §5.4, BR-AT10.';

    /** @var array<class-string<Model>, list<string>>|null */
    private ?array $map = null;

    /**
     * Model class => salary-bearing field names, for every model that declares any.
     *
     * Resolved once per request. The service is bound as a singleton, so the discovery below
     * runs at most once even though the reader may filter several queries.
     *
     * @return array<class-string<Model>, list<string>>
     */
    public function map(): array
    {
        return $this->map ??= $this->discover();
    }

    /** Is this (model, field) pair salary-bearing? */
    public function covers(string $modelClass, string $field): bool
    {
        return in_array($field, $this->map()[$modelClass] ?? [], true);
    }

    /** Every declared pair, flattened. @return list<array{model: string, field: string}> */
    public function pairs(): array
    {
        $pairs = [];

        foreach ($this->map() as $model => $fields) {
            foreach ($fields as $field) {
                $pairs[] = ['model' => $model, 'field' => $field];
            }
        }

        return $pairs;
    }

    /** For tests: forget the cached map. */
    public function flush(): void
    {
        $this->map = null;
    }

    /**
     * @return array<class-string<Model>, list<string>>
     */
    private function discover(): array
    {
        $map = [];

        foreach (self::modelClasses() as $class) {
            $fields = defined($class.'::SALARY_FIELDS') ? constant($class.'::SALARY_FIELDS') : [];

            if ($fields !== []) {
                $map[$class] = array_values($fields);
            }
        }

        return $map;
    }

    /**
     * Every Eloquent model in the application.
     *
     * ⚠ Discovery is by scan rather than by a hand-kept list on purpose. A list would have to
     * be updated by the same person who forgot to declare SALARY_FIELDS in the first place,
     * so it would be missing exactly when it mattered. The architecture test reuses this
     * method, so the filter and its guard can never disagree about which models exist.
     *
     * @return list<class-string<Model>>
     */
    public static function modelClasses(): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
            $class = 'App\\Models\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                $file->getRelativePathname()
            );

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }
}
