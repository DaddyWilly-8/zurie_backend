<?php

namespace App\Modules\Account\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Reserved for Account-module-specific bindings as the module grows.
 * Routes are loaded from routes/api.php directly so they're registered
 * inside the framework's `api` middleware group.
 */
class AccountModuleServiceProvider extends ServiceProvider
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
