<?php

namespace App\Providers;

use App\Services\Auth\ReadScopeResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton so the resolved scope is cached PER REQUEST — a Laravel singleton lives
        // exactly one request. Never cache this in the session: a transfer or a hierarchy
        // correction must take effect on the account's next request, not on their next
        // login (auth-rbac.spec.md §5.4, adr/0005 decision 2).
        $this->app->singleton(ReadScopeResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
