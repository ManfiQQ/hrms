<?php

use App\Http\Controllers\Auth\ActivationController;
use App\Http\Controllers\Auth\ActivationImageController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\DashboardController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePasswordIsChanged;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

/*
 * ⚠ The activation landing sits OUTSIDE every gate, and outside `guest` too.
 *
 * Outside the authenticated groups because the visitor has no session yet. Outside `guest`
 * because much of this workforce activates on a SHARED TERMINAL: a scan arriving while
 * somebody else's session is open must replace it, not be refused. RedeemActivationToken
 * regenerates the session for exactly that reason.
 *
 * ⚠ It is the highest-value unauthenticated route in the system — redeeming authenticates
 * the holder and lets them set the account's first password. Its protection is that the
 * token is 64 random characters, single-use, and dead after 48 hours; there is nothing here
 * to rate-limit that would not also lock out the employee it belongs to.
 */
Route::get('/activate/{token}', ActivationController::class)->name('activation.redeem');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

/*
 * ⚠ Both account gates are applied HERE, to the whole authenticated group, and not inside
 * controllers.
 *
 * auth-rbac.spec.md §5.2 requires the freeze to be enforced as middleware because a
 * policy-by-policy check is the one forgotten in the twentieth policy — and the omission
 * returns a successful write rather than an error. BR-A23's gate is the same shape: a
 * per-controller check would leave a page working for an account still holding the password
 * HR typed for it.
 *
 * Order matters. EnsureAccountIsActive runs first: an account whose employment has ended
 * should be refused before anything asks it to pick a new password.
 */
Route::middleware(['auth', EnsureAccountIsActive::class, EnsurePasswordIsChanged::class])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    /*
     * ⚠ The activation QR image — HR and Master Admin only (UserPolicy).
     *
     * It is a CREDENTIAL, not a report: whoever holds it can activate the account, because
     * redemption authenticates the holder outright. Inside the authenticated group and behind
     * a policy for that reason, and never cached.
     *
     * Serving it stamps activation_downloaded_at if unset (BR-A22) — the system records what
     * it can observe, and a second fetch does not move the timestamp.
     */
    Route::get('/accounts/{user}/activation.png', ActivationImageController::class)
        ->name('activation.image');

    /*
     * The account management screen (§7) — HR and Master Admin only, enforced by
     * UserPolicy::manageAccount inside the Livewire component rather than by route
     * middleware, because every Livewire action is its own request and a mount-time check
     * would authorise once for the life of the page.
     */
    Route::get('/accounts/{user}', fn (App\Models\User $user) => view('accounts.manage', ['user' => $user]))
        ->name('accounts.manage');

    /*
     * The employee list (employee-master.spec.md §5.4, §7) — same shape as the two screens
     * below: a thin closure returning the wrapper view, with EmployeePolicy::viewAny enforced
     * inside the Livewire component rather than by route middleware, because every Livewire
     * action is its own request and a mount-time check would authorise once for the life of
     * the page.
     *
     * ⚠ The route gate is only the door. WHICH employees the page shows is
     * Employee::scopeVisibleTo(), and a failure there is silent — it renders fewer or more
     * rows, correctly formatted, with nothing to report.
     */
    Route::get('/employees', fn () => view('employees.index'))->name('employees.index');

    /*
     * Master Admin management (§5.8) — Master Admin only, enforced by
     * UserPolicy::administerMasterAdmins inside the component, and BR-A13's 3/1 limits
     * enforced inside the Actions rather than by either.
     */
    Route::get('/master-admins', fn () => view('accounts.master-admins'))->name('master-admins');

    Route::get('/password/change', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::post('/password/change', [PasswordChangeController::class, 'update'])->name('password.change.update');

    // POST, so a link cannot trigger it and a prefetch cannot log someone out.
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
