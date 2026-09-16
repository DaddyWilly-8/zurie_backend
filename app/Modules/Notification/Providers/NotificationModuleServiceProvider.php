<?php

namespace App\Modules\Notification\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Reserved for Notification-module-specific bindings as the module grows.
 * Routes are loaded from routes/api.php directly so they're registered
 * inside the framework's `api` middleware group.
 */
class NotificationModuleServiceProvider extends ServiceProvider
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
