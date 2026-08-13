<?php

namespace Database\Factories\Concerns;

use App\Models\User;
use App\Services\Audit\AuthorshipContext;
use App\Services\Audit\AuthorshipContext as Authorship;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixtures enter `AuthorshipContext` deliberately — `adr/0009` decision 2.
 *
 * ⚠ THE SHORTCUT IS TAKEN ON PURPOSE, IN ONE PLACE, WITH A WRITTEN REASON. That is the whole
 * argument for a context class over a `runningInConsole()` exemption, and it is the same shape
 * `EmployeeRoleFactory` already uses for BR-16's restricted roles.
 *
 * Without this, every fixture in the suite would have to log a user in before building an
 * employee — turning the guard into an obstacle tests route around, which is how guards get
 * weakened until somebody switches one off.
 *
 * ⚠ WHAT THIS MEANS FOR TESTS OF `adr/0009` ITSELF, and it is the same warning
 * `EmployeeRoleFactory` carries: **they must not use a factory for the attempt under test.**
 * A test asserting *"a write with no authenticated user is refused"* has to go through the
 * real path — `Model::create()`, or the Action — or it proves only that this bypass works.
 * Building the surrounding fixtures with a factory is fine; performing the act under test
 * with one is not.
 */
trait AttributesAuthorship
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return app(AuthorshipContext::class)->run(
            $this->authorshipActor(),
            'Test and seed fixtures are attributed to the acting or installing account. '
            .'adr/0009 governs application writes, which resolve their actor from the session.',
            fn () => $this->createAttributed($attributes, $parent)
        );
    }

    /**
     * The actual insert, separated so a factory with a SECOND deliberate bypass can wrap it
     * without duplicating the authorship reasoning above — `EmployeeRoleFactory` does exactly
     * that for BR-16.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createAttributed($attributes = [], ?Model $parent = null)
    {
        return parent::create($attributes, $parent);
    }

    /**
     * Who the fixture is attributed to.
     *
     * ⚠ Prefers the authenticated account, so a test that logs somebody in gets rows authored
     * by them — which is what makes assertions about authorship meaningful rather than
     * uniform. Falls back to any existing account before creating one, so a suite does not
     * accumulate a stray user per fixture.
     *
     * ⚠ The created fallback is STANDARD, never FULL. A Master Admin conjured here would be
     * counted by BR-A13's 3-account limit tests and by anything asserting how many Master
     * Admins exist — a fixture detail silently changing the thing under test.
     *
     * `users` carries no authorship columns of its own, so this cannot recurse.
     */
    private function authorshipActor(): User
    {
        return auth()->user()
            ?? User::query()->orderBy('id')->first()
            ?? User::factory()->create(['system_access' => 'STANDARD', 'employee_id' => null]);
    }
}
