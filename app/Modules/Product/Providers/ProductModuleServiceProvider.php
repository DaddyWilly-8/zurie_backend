<?php

namespace App\Modules\Product\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Reserved for Product-module-specific bindings as the module grows.
 * Routes are loaded from routes/api.php directly so they're registered
 * inside the framework's `api` middleware group.
 */
class ProductModuleServiceProvider extends ServiceProvider
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
