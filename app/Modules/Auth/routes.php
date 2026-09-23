<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\CustomerAuthController;
use App\Modules\Auth\Controllers\PermissionController;
use App\Modules\Auth\Controllers\RoleController;
use App\Modules\Auth\Controllers\TwoFactorController;
use App\Modules\Auth\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// ---------------------------------------------------------------------
// Staff auth ('web' guard) — session/cookie based (Sanctum SPA), no
// bearer token. No self-registration, no Google login: staff accounts
// are created by an existing admin (UserController::store()). See the
// "Customer auth" block below for the storefront's fully separate
// counterpart — CustomerAccount's docblock has the full reasoning for
// why the two guards are kept independent.
// ---------------------------------------------------------------------

// `throttle:login` (5/min, keyed by email+IP — see AppServiceProvider) is
// on top of the generic 60/min `api` limiter every route already gets, not
// instead of it — the generic one was never enough on its own to stop
// sustained password guessing. See zurie-backend-security-audit.md, item #1.
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');

// Login-time 2FA challenge — the caller isn't authenticated yet (that's
// the whole point), so this stays outside auth:sanctum; see
// AuthService::challengeTwoFactor()'s docblock for what actually
// authorizes it (the short-lived session marker attempt() left).
Route::post('auth/two-factor/challenge', [AuthController::class, 'twoFactorChallenge'])->middleware('throttle:two-factor');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/user', [AuthController::class, 'user']);

    // Self-service only — always $request->user(), never an id from the
    // request (see TwoFactorController's docblock). No permission gate:
    // any authenticated staff account manages their own 2FA, the same
    // "self service" posture as AccountController.
    Route::get('auth/two-factor/status', [TwoFactorController::class, 'status']);
    Route::post('auth/two-factor/enable', [TwoFactorController::class, 'enable']);
    Route::post('auth/two-factor/confirm', [TwoFactorController::class, 'confirm']);
    Route::post('auth/two-factor/disable', [TwoFactorController::class, 'disable']);

    Route::post('users', [UserController::class, 'store'])->middleware('permission:user_manage');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:user_manage');
    Route::post('users/{user}/roles', [UserController::class, 'assignRole'])->middleware('permission:user_manage');
    Route::post('roles/{role}/permissions', [RoleController::class, 'assignPermission'])->middleware('permission:user_manage');

    Route::get('admin/users', [UserController::class, 'index'])->middleware('permission:user_manage');
    Route::patch('admin/users/{user}', [UserController::class, 'update'])->middleware('permission:user_manage');

    Route::get('admin/roles', [RoleController::class, 'index'])->middleware('permission:user_manage');
    Route::patch('admin/roles/{role}', [RoleController::class, 'update'])->middleware('permission:user_manage');
    Route::get('admin/permissions', [PermissionController::class, 'index'])->middleware('permission:user_manage');
});

// ---------------------------------------------------------------------
// Customer auth ('customer' guard) — the storefront's own login, fully
// independent of the block above despite sharing the same session
// cookie. `auth:customer` (Laravel's core Authenticate middleware, not
// Sanctum's) is used instead of `auth:sanctum` deliberately — the api
// group's statefulApi() middleware (bootstrap/app.php) has already made
// every request here session-aware regardless of which guard checks it
// afterward, so this needs no Sanctum guard-array change and leaves
// every staff route above completely unaffected.
// ---------------------------------------------------------------------

Route::post('customer/auth/login', [CustomerAuthController::class, 'login'])->middleware('throttle:login');
Route::post('customer/auth/register', [CustomerAuthController::class, 'register'])->middleware('throttle:register');
Route::post('customer/auth/forgot-password', [CustomerAuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
Route::post('customer/auth/reset-password', [CustomerAuthController::class, 'resetPassword'])->middleware('throttle:password-reset');

// Same reasoning as the staff Google block previously here — a plain
// browser navigation, not XHR, and Google's own redirect back to us
// carries a Referer Sanctum's stateful check won't recognize, so this
// stays in the `web` middleware group with EnsureFrontendRequestsAreStateful
// stripped back out (see the git history of this file for the original,
// more detailed incident writeup — InvalidStateException from a double
// session bootstrap).
Route::middleware('web')->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)->group(function (): void {
    Route::get('customer/auth/google/redirect', [CustomerAuthController::class, 'redirectToGoogle']);
    Route::get('customer/auth/google/callback', [CustomerAuthController::class, 'handleGoogleCallback']);
});

Route::middleware('auth:customer')->group(function (): void {
    Route::post('customer/auth/logout', [CustomerAuthController::class, 'logout']);
    Route::get('customer/auth/user', [CustomerAuthController::class, 'user']);
});
