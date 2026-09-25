<?php

use App\Modules\Auth\Middleware\EnsurePermission;
use App\Modules\CashierSession\Exceptions\CashierSessionAlreadyClosedException;
use App\Modules\CashierSession\Exceptions\CashierSessionAlreadyOpenException;
use App\Modules\CashierSession\Exceptions\NoOpenCashierSessionException;
use App\Modules\Coupon\Exceptions\InvalidCouponException;
use App\Modules\Finance\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Order\Exceptions\InvalidOrderTransitionException;
use App\Support\Http\EnsureIdempotency;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enables Sanctum's SPA cookie auth for the api group.
        $middleware->statefulApi();

        // Attaches throttle:api to the api group — the named "api" limiter
        // itself is defined in AppServiceProvider::boot().
        $middleware->throttleApi();

        $middleware->alias([
            'permission' => EnsurePermission::class,
            'idempotent' => EnsureIdempotency::class,
        ]);

        // This whole app is a JSON API with no login-page route — but
        // Laravel's default Authenticate middleware only skips its
        // redirect-to-login behavior when the request "expects JSON"
        // (an Accept: application/json header, or an XHR/pjax request).
        // Any other unauthenticated request — a plain curl call, a bot, a
        // misconfigured client, or an attacker's authorization sweep —
        // falls into the redirect path instead, which tries to build a
        // URL for a named "login" route that doesn't exist here and
        // throws RouteNotFoundException: a raw 500 instead of a clean
        // 401. Forcing this to null makes every unauthenticated request,
        // regardless of headers, always throw AuthenticationException —
        // already mapped to the standard { success: false, message:
        // "Unauthenticated." } 401 below.
        Authenticate::redirectUsing(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Error monitoring — a no-op until SENTRY_LARAVEL_DSN is set (see
        // config/sentry.php; the SDK itself skips capturing entirely with
        // no DSN configured, so this is safe to leave wired in every
        // environment, local included). This is the documented Laravel
        // 11+/bootstrap-style integration point for Sentry's Laravel SDK —
        // it reports every exception that reaches this handler (after our
        // own JSON-envelope render() below still runs; reporting and
        // rendering are independent), so a real production incident is
        // visible somewhere other than a log file nobody's watching.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Every error response — validation, auth, not-found, throttling, or
        // an uncaught exception — must still follow the single envelope
        // shape from zurie-api-contract.md §1: { success: false, message,
        // errors? }. Laravel's own default renderers don't include
        // "success" at all, so without this every error path in the API
        // would silently drift from the contract.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            if ($e instanceof AuthorizationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'This action is unauthorized.',
                ], 403);
            }

            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                ], 404);
            }

            // Checkout tried to order more of a product than is currently in
            // stock — an expected, preventable user action (or a genuine
            // race two concurrent checkouts lost to InventoryService's
            // lockForUpdate()), not a real server error. See
            // zurie-backend-implementation-spec.md §7.
            if ($e instanceof InsufficientStockException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            // An order status change or cancel request that violates a
            // transition rule (cancelling a delivered/already-cancelled
            // order, changing status on an order that's already
            // cancelled) — expected, preventable admin action, not a
            // server error. See zurie-backend-implementation-spec.md §7.
            if ($e instanceof InvalidOrderTransitionException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            // Cashier session state violations (opening a second session on
            // an outlet that already has one open, closing an already-
            // closed session, or reconciling an outlet with no open
            // session) — expected, preventable admin actions, not server
            // errors, same treatment as InvalidOrderTransitionException.
            if ($e instanceof CashierSessionAlreadyOpenException
                || $e instanceof CashierSessionAlreadyClosedException
                || $e instanceof NoOpenCashierSessionException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            // An invalid/expired/exhausted coupon code applied at checkout
            // — expected, preventable customer action, not a server error.
            if ($e instanceof InvalidCouponException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            // A journal entry whose debits and credits don't sum to the same
            // total — never written to the database (checked before the
            // transaction starts), so this always indicates a bug in the
            // caller assembling the lines, not a data-integrity problem to
            // recover from. 500, not 422 — this isn't a preventable user
            // action like the two exceptions above.
            if ($e instanceof UnbalancedJournalEntryException) {
                return response()->json([
                    'success' => false,
                    'message' => config('app.debug') ? $e->getMessage() : 'Internal Server Error.',
                ], 500);
            }

            // Same-module FK constraint violations (e.g. deleting a Category
            // that still has Products pointing at it via categories.id) —
            // this is an expected, preventable user action, not a real
            // server error, so it gets its own status/message rather than
            // falling into the generic 500 branch below. SQLSTATE 23000 is
            // "integrity constraint violation" across MySQL/Postgres/SQLite.
            if ($e instanceof QueryException && $e->getCode() === '23000') {
                return response()->json([
                    'success' => false,
                    'message' => 'This record cannot be deleted or saved because it is still referenced by other data.',
                ], 409);
            }

            // Covers everything else that already carries a real HTTP status
            // (404 route-not-found, 405 method-not-allowed, 429 throttled, etc.)
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                return response()->json([
                    'success' => false,
                    'message' => $status === 429
                        ? 'Too many requests. Please try again later.'
                        : ($e->getMessage() ?: 'Request failed.'),
                ], $status);
            }

            // Anything uncaught — never leak the real exception message or a
            // stack trace in production, per §14's "no internal errors
            // leaked" requirement.
            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Internal Server Error.',
            ], 500);
        });
    })->create();
