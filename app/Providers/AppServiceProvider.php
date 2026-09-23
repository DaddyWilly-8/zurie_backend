<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Backs the `throttle:api` middleware attached to the api group in
        // bootstrap/app.php — required by zurie-backend-implementation-spec.md §14.
        // Keyed by user ID when authenticated, falling back to IP for public
        // endpoints (checkout, contact, newsletter, etc.).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Backs `throttle:login` on POST /auth/login (see
        // App\Modules\Auth\routes.php) — the generic 60/min `api` limiter
        // above is far too loose for a login endpoint on its own (60
        // password guesses/minute, forever, with no lockout). Keyed by
        // email+IP together, not IP alone: an attacker rotating IPs still
        // can't reset their budget against one target email, and a
        // legitimate user can't get another user's IP-based budget
        // exhausted by an attacker hammering a third email from the same
        // network. A 429 here is already reshaped into the standard error
        // envelope by bootstrap/app.php's HttpExceptionInterface branch —
        // no separate response() needed. See
        // zurie-backend-security-audit.md, item #1.
        RateLimiter::for('login', function (Request $request) {
            $key = Str::lower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // Backs `throttle:checkout` on POST /orders (see
        // App\Modules\Order\routes.php) — public, unauthenticated checkout
        // only had the generic 60/min `api` limiter, loose enough to spam
        // fake orders, exhaust real stock against InsufficientStockException
        // checks, or flood the customers table (every checkout does a
        // firstOrCreate by phone). Keyed by IP only, unlike `login` — a
        // guest checkout has no account to key on instead. 15/min still
        // comfortably serves one real customer placing an order.
        // zurie-backend-security-audit.md, item #5.
        RateLimiter::for('checkout', function (Request $request) {
            return Limit::perMinute(15)->by($request->ip());
        });

        // Backs `throttle:register` on POST /auth/register and
        // `throttle:password-reset` on POST /auth/forgot-password and
        // /auth/reset-password — a security review found these three had
        // only the generic 60/min `api` limiter, loose enough to enable
        // fast account-creation spam or password-reset-email harassment
        // (mass-triggering reset emails to arbitrary addresses) at up to
        // 60/minute per IP. Keyed by IP alone (unlike `login`'s email+IP
        // key) since these endpoints don't take a pre-existing account
        // identity the same way — an email is part of the payload being
        // created/targeted, not a stable key to rate-limit by.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Backs `throttle:two-factor` on POST /auth/two-factor/challenge.
        // A TOTP code is only 6 digits (1,000,000 possibilities) — far
        // more guessable than a password over enough attempts — so this
        // is deliberately tighter than the generic `login` limiter.
        // Keyed by session id + IP rather than email: this endpoint runs
        // mid-challenge, before the caller is re-authenticated, so there's
        // no user() to key by, and the pending challenge itself is already
        // scoped to one browser session (see AuthService::
        // challengeTwoFactor()).
        RateLimiter::for('two-factor', function (Request $request) {
            $key = $request->session()->getId().'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });
    }
}
