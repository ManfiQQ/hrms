<?php

namespace App\Providers;

use App\Services\Audit\AuditLogger;
use App\Services\Auth\MasterAdminContext;
use App\Services\Auth\ReadScopeResolver;
use App\Services\Auth\RestrictedRoleContext;
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

        // ⚠ Singleton for exactly the same reason, and the failure mode is the opposite one.
        // EmployeeRole's creating hook asks this whether a deliberate restricted-role grant
        // is in effect. A fresh instance per resolution would always answer "no", so every
        // seeder and importer entering the context would still be refused — the guard would
        // fail CLOSED and look like a bug in the caller rather than in the binding
        // (BR-16, adr/0003 decision 3).
        $this->app->singleton(RestrictedRoleContext::class);

        // Singleton because it holds the batch id for the life of the current transaction
        // (BR-AT12). A fresh instance per resolution would mint a new batch_id on every
        // call, so a three-field save would render as three unrelated changes — each row
        // individually correct, the grouping silently wrong, and nothing erroring.
        //
        // The constructor also registers the commit/rollback listeners that release the
        // batch, which must happen exactly once.
        $this->app->singleton(AuditLogger::class);

        // SecurityEventLogger is deliberately NOT a singleton: it holds no state, and
        // binding it would suggest it does.
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
