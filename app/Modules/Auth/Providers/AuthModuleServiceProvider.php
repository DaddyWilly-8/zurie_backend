<?php

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Models\User;
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
        //
        // Branches on $notifiable's class — the customer/staff split gave
        // `users` and `customer_accounts` separate password brokers
        // (config/auth.php `passwords`), but Laravel's ResetPassword
        // notification has no other way to tell this global callback
        // which broker triggered it. Sending both to the same
        // `/reset-password` frontend page would leave that page unable to
        // know which of the two (differently-shaped) reset endpoints to
        // call — see CLAUDE.md/ARCHITECTURE_GUIDE.md's customer/staff
        // split notes for the two frontend pages this maps to.
        ResetPassword::createUrlUsing(function ($notifiable, string $token): string {
            $email = urlencode($notifiable->getEmailForPasswordReset());
            $path = $notifiable instanceof User ? 'admin/reset-password' : 'reset-password';

            return rtrim(config('app.frontend_url'), '/')."/{$path}?token={$token}&email={$email}";
        });
    }
}
