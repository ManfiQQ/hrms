<?php

namespace App\Services\Audit;

use App\Models\User;
use RuntimeException;

/**
 * The explicit way to write authorship columns without an authenticated session —
 * `adr/0009` decision 2.
 *
 * ⚠ DELIBERATELY THE SAME SHAPE AS RestrictedRoleContext, AND NOT A SECOND DESIGN FOR ONE
 * PROBLEM. Both answer "a rule needs an actor, and this caller has no session"; both refuse
 * to be ambient; both demand a stated reason. Inventing a different mechanism here would
 * mean two escape hatches with two sets of habits, and the looser one would win.
 *
 * ⚠ WHAT IT DOES NOT DO: make a write anonymous. `run()` takes a USER, not a flag. The
 * shortcut is for *"no authenticated session"* — a seeder, a console command, the legacy
 * importer — never for *"no accountable actor"*. `adr/0009` decision 2 refuses a silent NULL
 * for exactly that reason: a column that admits ignorance is better than one that states a
 * confident falsehood, but a column that records nobody is neither.
 *
 * The seeded Master Admin is the actor a seeder names, because it genuinely is the account
 * the installation acts as.
 */
class AuthorshipContext
{
    private ?User $actor = null;

    private ?string $reason = null;

    /**
     * Run a callback with `$actor` attributed as the author of everything written inside it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(User $actor, string $reason, callable $callback): mixed
    {
        if (trim($reason) === '') {
            throw new RuntimeException(
                'Writing authorship columns outside an authenticated session requires a stated '
                .'reason. The shortcut is deliberate or it is not taken (adr/0009 decision 2).'
            );
        }

        $previousActor = $this->actor;
        $previousReason = $this->reason;

        $this->actor = $actor;
        $this->reason = $reason;

        try {
            return $callback();
        } finally {
            // Restored rather than reset, so a nested call cannot end its parent's context —
            // a seeder calling another seeder must not leave the outer one unattributed.
            $this->actor = $previousActor;
            $this->reason = $previousReason;
        }
    }

    /** True only inside run(). AuthorshipObserver consults this and nothing else. */
    public function isActive(): bool
    {
        return $this->actor !== null;
    }

    /** The account to attribute writes to, or null outside the context. */
    public function actorId(): ?int
    {
        return $this->actor?->getKey();
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
