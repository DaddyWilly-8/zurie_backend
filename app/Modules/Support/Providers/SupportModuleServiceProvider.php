<?php

namespace App\Modules\Support\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Reserved for Support-module-specific bindings as the module grows.
 * Routes are loaded from routes/api.php directly so they're registered
 * inside the framework's `api` middleware group.
 */
class SupportModuleServiceProvider extends ServiceProvider
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
