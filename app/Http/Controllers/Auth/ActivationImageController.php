<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\ActivationImage;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the activation QR image — auth-rbac.spec.md §5.6, BR-A22.
 *
 * ⚠ SERVING THE IMAGE IS WHAT RECORDS THE DOWNLOAD. There is no button and no user action:
 * `activation_downloaded_at` is stamped here because fetching the image is the only part of
 * delivery the system can OBSERVE.
 *
 * A "mark as sent" button was rejected — it records an assertion, not a fact, and a timestamp
 * reading "HR sent this at 2:15pm" looks authoritative while meaning only that somebody
 * clicked. The download is observable and settles half the question with certainty:
 * **if it was never downloaded, it was certainly never sent.**
 */
class ActivationImageController extends Controller
{
    public function __invoke(User $user, ActivationImage $image): Response
    {
        // The image is a credential — whoever holds it can activate the account. HR and
        // Master Admin only (§6); the decision itself lives in UserPolicy.
        // Gate::authorize, not $this->authorize: Laravel 11 removed AuthorizesRequests from
        // the base controller, and adding the trait back would put an authorisation helper on
        // every controller in the application to serve one route.
        Gate::authorize('viewActivationImage', $user);

        $png = $image->render($user);

        // ⚠ STAMPED ONLY IF NOT ALREADY SET (BR-A22). A second download must NOT move it:
        // the timestamp answers "when did HR first take possession of this image", and
        // overwriting it on every fetch would silently convert that into "when did somebody
        // last look", which is a different question nobody asked. §8 test 30.
        //
        // Stamped after rendering: a failed render must not record a download that never
        // happened.
        if ($user->activation_downloaded_at === null) {
            $user->forceFill(['activation_downloaded_at' => now()])->save();
        }

        $response = response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="activation.png"',
        ]);

        // ⚠ Never cached, and set as directives rather than a raw header string — Symfony
        // recomputes Cache-Control and silently drops a hand-written value, which would leave
        // a credential cacheable while the code appeared to forbid it.
        //
        // A credential sitting in a proxy or a browser cache outlives the access check that
        // produced it.
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('no-cache');
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->setPrivate();

        return $response;
    }
}
