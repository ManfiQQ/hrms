<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RedeemActivationToken;
use App\Exceptions\Auth\InvalidActivationTokenException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * The activation landing, reached by scanning the QR — auth-rbac.spec.md §5.6, §7.
 *
 * Thin by requirement: the atomicity, the three rejections, the authentication and the
 * security-event write all live in RedeemActivationToken.
 *
 * ⚠ Outside every authenticated middleware group, and it has to be — the visitor has no
 * session yet. It is also outside `guest`: much of this workforce activates on a SHARED
 * TERMINAL, and a scan arriving while somebody else's session is open must replace it rather
 * than be refused. The Action regenerates the session for exactly that reason.
 */
class ActivationController extends Controller
{
    public function __invoke(string $token, RedeemActivationToken $redeem): RedirectResponse
    {
        try {
            $redeem->execute($token);
        } catch (InvalidActivationTokenException $e) {
            // ⚠ One message for used, expired and unknown. Rendering getMessage() cannot leak
            // which of the three it was, because the exception carries only the one text.
            return redirect()->route('login')->withErrors(['phone_no' => $e->getMessage()]);
        }

        // BR-A23's gate takes over from here: `must_change_password` is still true, so every
        // route except the password screen redirects there. This controller does not need to
        // know that, and repeating it would be a second place for the rule to be wrong.
        return redirect()->route('dashboard');
    }
}
