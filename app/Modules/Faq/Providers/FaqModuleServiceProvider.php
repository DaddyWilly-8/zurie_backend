<?php

namespace App\Modules\Faq\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Reserved for Faq-module-specific bindings as the module grows. Routes
 * are loaded from routes/api.php directly so they're registered inside
 * the framework's `api` middleware group.
 */
class FaqModuleServiceProvider extends ServiceProvider
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
