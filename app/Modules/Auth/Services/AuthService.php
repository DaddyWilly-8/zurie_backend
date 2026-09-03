<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Sanctum SPA cookie auth: establishes the session cookie, no token returned.
     */
    public function attempt(string $email, string $password): User
    {
        if (! Auth::guard('web')->attempt(['email' => $email, 'password' => $password])) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        request()->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user->load('roles.permissions');
    }

    public function logout(): void
    {
        Auth::guard('web')->logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }

    /**
     * Called on app load, and again after any role/permission-changing action
     * succeeds — permissions carried on the session are UI convenience only.
     */
    public function currentUser(User $user): User
    {
        return $user->load('roles.permissions');
    }

    /**
     * Kills every active session for this user — called after a password
     * reset (AuthController::resetPassword()), so a session that predates
     * a compromised/forgotten-password event doesn't stay valid until it
     * naturally idles out. Deliberately not scoped to "other" sessions —
     * password resets happen anonymously via a mailed token, there's no
     * "current" session of the resetting party to preserve.
     *
     * Only meaningful with SESSION_DRIVER=database — see
     * zurie-backend-implementation-spec.md §20, "Still open". On any other
     * driver this is a harmless no-op (the sessions table exists
     * regardless of driver, just unused), not an error — deliberately not
     * guarded behind a config check, since a no-op is the correct behavior
     * either way, not a failure.
     */
    public function invalidateSessionsFor(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
