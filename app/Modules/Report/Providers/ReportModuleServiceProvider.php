<?php

namespace App\Modules\Report\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Reserved for Report-module-specific bindings as the module grows.
 * Routes are loaded from routes/api.php directly so they're registered
 * inside the framework's `api` middleware group.
 */
class ReportModuleServiceProvider extends ServiceProvider
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
