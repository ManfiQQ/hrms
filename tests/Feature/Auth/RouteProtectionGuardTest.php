<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Two guards over the route table, written after `serve => true` on the `local` disk was found
 * registering an unauthenticated PUT route into this application (conventions.md §9).
 *
 * ⚠ NEITHER GUARD IS AN ALLOWLIST, AND THAT IS THE WHOLE DESIGN CONSTRAINT. Three routes here
 * legitimately carry no middleware — Livewire's two asset routes and Laravel's `/up` health
 * check — so the obvious guard, *"no route may lack middleware"*, is red on arrival. The
 * obvious fix for that is a list of permitted exceptions, and a list is what
 * AuthorshipCoverageTest warns against by name: a guard compared against a second copy of the
 * same names agrees with itself forever. Adding an unprotected route plus its name to the list
 * would pass.
 *
 * So both assertions below encode a PROPERTY instead. Neither needs editing when a legitimate
 * unprotected asset route appears, and neither can be satisfied by declaring an exception.
 *
 * ⚠ WHAT THEY DO NOT CATCH, stated because a guard that is trusted beyond its reach is worse
 * than none: an unprotected **GET** route registered by **vendor** code. That is the exact
 * shape of `GET /storage/{path}`, and only the write half of it is covered here. A read route
 * opened by some future framework default would pass both assertions. Closing that would take
 * an inventory of vendor routes, which is the allowlist this test exists to avoid.
 */

/** @return list<RoutingRoute> */
function applicationRoutes(): array
{
    return array_values(Route::getRoutes()->getRoutes());
}

/** Where a route was defined: an `App\` controller, or a closure inside routes/. */
function isApplicationDefined(RoutingRoute $route): bool
{
    $action = $route->getAction('uses');

    if (is_string($action)) {
        return str_starts_with($action, 'App\\');
    }

    if ($action instanceof Closure) {
        $file = (new ReflectionFunction($action))->getFileName();

        return $file !== false && str_starts_with($file, base_path('routes'));
    }

    return false;
}

/**
 * ⚠ THE ASSERTION THAT WOULD HAVE CAUGHT IT. A route that changes state and asks nobody who is
 * calling has no business existing, whoever registered it — this application or a package
 * default nobody chose.
 *
 * Verb-based rather than name-based on purpose: it stays true as routes come and go, and a
 * package that opens a write route in a future upgrade fails it without anyone having thought
 * to look.
 */
it('exposes no write route without middleware', function () {
    $writeRoutes = array_filter(
        applicationRoutes(),
        fn (RoutingRoute $route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []
    );

    // ⚠ The empty-collection habit conventions.md §9 requires. A filter that matched nothing
    // would make the assertion below pass forever while checking no routes at all — the first
    // of the three empty guards that section records.
    expect($writeRoutes)->not->toBeEmpty();

    $unprotected = array_map(
        fn (RoutingRoute $route) => $route->methods()[0].' '.$route->uri(),
        array_filter($writeRoutes, fn (RoutingRoute $route) => $route->gatherMiddleware() === [])
    );

    expect($unprotected)->toBe([], 'A route that writes must not be reachable without middleware: '.implode(', ', $unprotected));
});

/**
 * The second property: routes THIS PROJECT defines carry middleware, read or write.
 *
 * Vendor routes are out of scope here because we cannot fix them by editing our own routes
 * file — the answer to one of those is to turn the package feature off, which is what happened
 * to `serve` and what the first assertion enforces.
 */
it('defines no route of its own without middleware', function () {
    $ours = array_filter(applicationRoutes(), isApplicationDefined(...));

    expect($ours)->not->toBeEmpty();

    $unprotected = array_map(
        fn (RoutingRoute $route) => $route->methods()[0].' '.$route->uri(),
        array_filter($ours, fn (RoutingRoute $route) => $route->gatherMiddleware() === [])
    );

    expect($unprotected)->toBe([], 'Routes defined by this application must carry middleware: '.implode(', ', $unprotected));
});

/**
 * ⚠ The configuration fact behind the first assertion, asserted directly so the reason survives
 * even if the route table changes shape.
 *
 * `serve` on a local disk is not a URL convenience — it is route registration
 * (FilesystemServiceProvider::shouldServeFiles), and this disk is where employee IC scans and
 * passports will be stored.
 */
it('serves no local disk over HTTP', function () {
    $served = array_keys(array_filter(
        config('filesystems.disks'),
        fn (array $disk) => ($disk['driver'] ?? null) === 'local' && ($disk['serve'] ?? false)
    ));

    expect(config('filesystems.disks'))->not->toBeEmpty()
        ->and($served)->toBe([], 'A served local disk registers unauthenticated GET and PUT routes: '.implode(', ', $served));
});
