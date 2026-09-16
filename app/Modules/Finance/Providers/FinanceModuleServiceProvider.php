<?php

namespace App\Modules\Finance\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Reserved for Finance-module-specific bindings as the module grows.
 * Routes are loaded from routes/api.php directly so they're registered
 * inside the framework's `api` middleware group.
 */
class FinanceModuleServiceProvider extends ServiceProvider
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
