<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordChangeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The forced password change — BR-A23.
 *
 * Reached by the gate while `must_change_password` is true, and by nothing else yet: the
 * account-management screen where HR resets another person's password (§7) is not built.
 */
class PasswordChangeController extends Controller
{
    public function show(): View
    {
        return view('auth.password-change');
    }

    public function update(PasswordChangeRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => $request->string('password')->toString(),   // hashed by the model cast
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        // ⚠ The session id changes on a credential change. Anything that captured the old id
        // — a shared terminal, a shoulder-glance at a URL — must not survive the password
        // that replaced it.
        //
        // There is no remember-me cookie to invalidate here, and that is BR-A4 paying for
        // itself: a persistent login would be a second credential, and this is exactly the
        // moment someone would forget to revoke it.
        $request->session()->regenerate();

        return redirect()
            ->route('dashboard')
            ->with('status', 'Your password has been changed.');
    }
}
