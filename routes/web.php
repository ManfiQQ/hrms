<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\DashboardController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePasswordIsChanged;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

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

    Route::get('/password/change', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::post('/password/change', [PasswordChangeController::class, 'update'])->name('password.change.update');

    // POST, so a link cannot trigger it and a prefetch cannot log someone out.
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
