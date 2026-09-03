<?php

namespace App\Modules\Auth\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

/**
 * Reserved for Auth-module-specific bindings (e.g. repository interfaces)
 * as the module grows. Routes are loaded from routes/api.php directly so
 * they're registered inside the framework's `api` middleware group.
 */
class AuthModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // This is an API-only backend with no "password.reset" web route —
        // the reset UI lives on the separate frontend deployment. Point the
        // notification's link there instead of letting it try to resolve a
        // Laravel route that doesn't exist.
        ResetPassword::createUrlUsing(function ($notifiable, string $token): string {
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return rtrim(config('app.frontend_url'), '/')."/reset-password?token={$token}&email={$email}";
        });
    }
}
