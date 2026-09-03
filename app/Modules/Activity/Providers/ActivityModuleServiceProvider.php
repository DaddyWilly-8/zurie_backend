<?php

namespace App\Modules\Activity\Providers;

use App\Modules\Activity\Listeners\LogFailedLogin;
use App\Modules\Activity\Listeners\LogLogout;
use App\Modules\Activity\Listeners\LogPasswordReset;
use App\Modules\Activity\Listeners\LogSuccessfulLogin;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Activity has no direct dependency on Auth's classes beyond these four
 * native Laravel auth events — it reacts to them the same way Inventory
 * reacts to Product's events, never a direct call. Model-level create/
 * update/delete logging (Product, Category, User, Role) is handled by
 * spatie/laravel-activitylog's LogsActivity trait directly on those models,
 * not through listeners here — see zurie-backend-implementation-spec.md §12.
 */
class ActivityModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Listeners live under app/Modules/Activity/Listeners, outside
        // Laravel's default app/Listeners auto-discovery path, so they're
        // registered explicitly here rather than relying on discovery.
        Event::listen(Login::class, LogSuccessfulLogin::class);
        Event::listen(Logout::class, LogLogout::class);
        Event::listen(Failed::class, LogFailedLogin::class);
        Event::listen(PasswordReset::class, LogPasswordReset::class);
    }
}
