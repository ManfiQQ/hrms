<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Thin, by requirement (auth-rbac.spec.md §5.1). Every decision lives in
 * AuthenticationService: normalisation, the throttle check that precedes the password check,
 * the counter, the security_events write, and session regeneration.
 *
 * ⚠ Nothing here may grow an authorization check or a business rule
 * (conventions.md §1). If a question can be answered differently for two accounts, it does
 * not belong in this file.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, AuthenticationService $auth): RedirectResponse
    {
        try {
            $auth->attempt($request->string('phone_no')->toString(), $request->string('password')->toString());
        } catch (InvalidCredentialsException $e) {
            // ⚠ One message for every cause — unknown number, wrong password, locked account.
            // AccountLockedException extends InvalidCredentialsException and carries the same
            // text deliberately, so this catch cannot leak the account's existence by
            // rendering getMessage() (BR-A3).
            throw ValidationException::withMessages([
                'phone_no' => $e->getMessage(),
            ])->redirectTo(route('login'));
        }

        // BR-A23's gate handles the redirect to the password screen; this controller does not
        // need to know about it, and duplicating the check here would be a second place for
        // the rule to be wrong.
        return redirect()->intended(route('dashboard'));
    }

    /**
     * Logout is a POST, so a link cannot trigger it and a prefetch cannot log someone out.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        // Both, and in this order. Invalidating clears the session data; regenerating the
        // token stops the old CSRF token being replayed against the new session.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
