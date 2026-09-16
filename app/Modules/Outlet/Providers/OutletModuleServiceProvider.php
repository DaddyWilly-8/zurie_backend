<?php

namespace App\Modules\Outlet\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Reserved for Outlet-module-specific bindings as the module grows.
 * Routes are loaded from routes/api.php directly so they're registered
 * inside the framework's `api` middleware group.
 */
class OutletModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
