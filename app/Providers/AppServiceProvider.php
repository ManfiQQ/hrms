<?php

namespace App\Providers;

use App\Services\Auth\MasterAdminContext;
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

        // Singleton because the scopes ask it whether a bypass is in effect. A fresh
        // instance per resolution would always answer "no", which fails safe but makes the
        // bypass silently inoperative (adr/0005 decision 5).
        $this->app->singleton(MasterAdminContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
