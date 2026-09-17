<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\PermissionController;
use App\Modules\Auth\Controllers\RoleController;
use App\Modules\Auth\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Public auth flow — session/cookie based (Sanctum SPA), no bearer token.
// `throttle:login` (5/min, keyed by email+IP — see AppServiceProvider) is
// on top of the generic 60/min `api` limiter every route already gets, not
// instead of it — the generic one was never enough on its own to stop
// sustained password guessing. See zurie-backend-security-audit.md, item #1.
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('auth/register', [AuthController::class, 'register']);
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);

// Plain browser-navigated GETs, not XHR — see AuthController::
// redirectToGoogle()'s docblock for why these can't be POST/JSON like the
// routes above. Forced into the `web` middleware group (unconditional
// StartSession) rather than relying on `api`'s statefulApi(), which only
// attaches session middleware when EnsureFrontendRequestsAreStateful
// recognizes the request's Origin/Referer as our frontend. That check
// passes for /redirect (Referer is our own frontend page), but NOT for
// /callback — Google's own redirect back to us carries Referer:
// accounts.google.com, not localhost:3000/zurie.co.tz, so the stateful
// check would never fire and session()->regenerate() inside
// AuthService::loginOrRegisterViaSocialite() would throw "Session store
// not set on request." `web`'s own CSRF check doesn't apply here (GET
// requests are exempt from VerifyCsrfToken regardless).
//
// EnsureFrontendRequestsAreStateful is explicitly stripped back out —
// these routes are still loaded via routes/api.php, so `api`'s group
// (which statefulApi() adds this to) still applies underneath `web`
// unless excluded. Leaving both active bootstraps TWO independent
// session-handling passes on the same request (this `web` group's own
// StartSession, plus Sanctum's own conditionally-pushed one whenever the
// Origin/Referer happens to match SANCTUM_STATEFUL_DOMAINS — which it
// does for our own frontend's Referer on /redirect). That double
// bootstrap is what broke Socialite's state round-trip in production:
// InvalidStateException started appearing only after the `web` group was
// added, where it hadn't before — see AuthController::
// handleGoogleCallback()'s log line, which is what surfaced this.
Route::middleware('web')->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)->group(function (): void {
    Route::get('auth/google/redirect', [AuthController::class, 'redirectToGoogle']);
    Route::get('auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/user', [AuthController::class, 'user']);

    Route::post('users', [UserController::class, 'store'])->middleware('permission:user_manage');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:user_manage');
    Route::post('users/{user}/roles', [UserController::class, 'assignRole'])->middleware('permission:user_manage');
    Route::post('roles/{role}/permissions', [RoleController::class, 'assignPermission'])->middleware('permission:user_manage');

    Route::get('admin/users', [UserController::class, 'index'])->middleware('permission:user_manage');
    Route::patch('admin/users/{user}', [UserController::class, 'update'])->middleware('permission:user_manage');

    // New — previously there was no way to list roles or permissions at
    // all, only write endpoints (POST /roles, POST /roles/{role}/permissions).
    // admin/ prefix per §2.4's standard, since these are new routes.
    Route::get('admin/roles', [RoleController::class, 'index'])->middleware('permission:user_manage');
    Route::patch('admin/roles/{role}', [RoleController::class, 'update'])->middleware('permission:user_manage');
    Route::get('admin/permissions', [PermissionController::class, 'index'])->middleware('permission:user_manage');
});
