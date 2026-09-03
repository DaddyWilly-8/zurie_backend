<?php

namespace App\Modules\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side permission enforcement — the `permissions` array returned to
 * the client is a UI convenience only, never the actual authorization
 * boundary. Every mutating endpoint must gate through this middleware
 * (or an equivalent policy check) regardless of what the client believes
 * it can do.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'message' => 'This action is unauthorized.',
            ], 403);
        }

        return $next($request);
    }
}
