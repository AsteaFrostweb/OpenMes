<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Every enabled module's provider, registered with the application as one.
 *
 * Laravel keys its provider list by class name (Application::markAsRegistered)
 * and boots what is in that list once every provider has registered. Each
 * module used to go in as its own ModuleProviderGuard — the same class every
 * time — so each guard overwrote the previous one's entry. `register()` still
 * ran for all of them, because Laravel calls it immediately, but `boot()` ran
 * only for the last module enabled: with two modules installed the first one
 * registered no routes, menus or migrations, and nothing was logged, because
 * nothing failed.
 *
 * Hence a single provider holding all the guards. Each module still runs
 * inside its own guard, so a module that throws costs that module alone.
 */
class ModuleProviders extends ServiceProvider
{
    /**
     * @param  list<ModuleProviderGuard>  $guards
     */
    public function __construct(Application $app, private readonly array $guards)
    {
        parent::__construct($app);
    }

    public function register(): void
    {
        foreach ($this->guards as $guard) {
            $guard->register();
        }
    }

    public function boot(): void
    {
        foreach ($this->guards as $guard) {
            $guard->boot();
        }
    }
}
