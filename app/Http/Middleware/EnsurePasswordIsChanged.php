<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-A23 — `must_change_password` gates everything.
 *
 * While the flag is true, every route except the password-change screen and logout redirects
 * there. **This applies to Master Admin equally**, and that is stated in the rule rather than
 * left to judgement: the seeder creates the first account with the flag set and the
 * credentials come from `.env`, so a Master Admin who could skip the gate would keep an
 * environment-file password as their real one indefinitely.
 *
 * ⚠ It is a middleware, not a check inside each controller. A per-controller check is the
 * one that gets forgotten on the twentieth controller, and the omission is invisible: the
 * page simply works, for an account still holding the password HR typed for it.
 *
 * The exemptions are named routes, not paths. A path list drifts the first time a route is
 * renamed, and it drifts silently — the gate would start redirecting the change screen to
 * itself, which is an infinite redirect rather than a security hole, but it is the same
 * class of mistake.
 */
class EnsurePasswordIsChanged
{
    /**
     * The only routes reachable while the flag is set.
     *
     * Logout is here because an account that cannot change its password and cannot leave is
     * trapped — and the person most likely to be trapped is someone who opened the system on
     * a shared terminal and wants out of it.
     */
    private const EXEMPT_ROUTES = [
        'password.change',
        'password.change.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs(self::EXEMPT_ROUTES)) {
            return $next($request);
        }

        return redirect()
            ->route('password.change')
            ->with('status', 'Set your own password before continuing.');
    }
}
