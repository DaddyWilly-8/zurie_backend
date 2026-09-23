<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(private readonly TwoFactorService $twoFactorService) {}

    /**
     * Sanctum SPA cookie auth: establishes the session cookie, no token
     * returned. When the account has 2FA confirmed, password success
     * alone is deliberately NOT enough — Auth::attempt() below briefly
     * authenticates the guard to obtain the User model (its own
     * `password` check is exactly what we want reused), then this method
     * immediately logs the guard back out and stores only a short-lived
     * "which user is mid-challenge" marker in the (regenerated) session,
     * never a real login, until challengeTwoFactor() proves the second
     * factor too. See its docblock for the full round trip.
     *
     * @return array{status: 'authenticated', user: User}|array{status: 'two_factor_required'}
     */
    public function attempt(string $email, string $password): array
    {
        if (! Auth::guard('web')->attempt(['email' => $email, 'password' => $password])) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        if ($user->hasTwoFactorEnabled()) {
            Auth::guard('web')->logout();

            // Regenerated even for this partial state — a session id an
            // attacker fixed before the password step must never carry
            // forward into the authenticated session the challenge step
            // produces (classic session-fixation defense, same reasoning
            // full login already applies below).
            request()->session()->regenerate();
            request()->session()->put('two_factor_user_id', $user->id);
            request()->session()->put('two_factor_expires_at', now()->addMinutes(5)->timestamp);

            return ['status' => 'two_factor_required'];
        }

        request()->session()->regenerate();

        return ['status' => 'authenticated', 'user' => $user->load('roles.permissions')];
    }

    /**
     * Second step of a 2FA login — completes the session AuthService::
     * attempt() deliberately left un-authenticated. Accepts either a live
     * TOTP code or a single-use recovery code (TwoFactorService tries
     * both). The pending marker is single-use and time-boxed to 5 minutes
     * — expired or already-consumed markers fail closed, forcing the
     * caller back through attempt() with their password again rather than
     * leaving a long-lived "half logged in" window an attacker who only
     * has the password (not the second factor) could sit on.
     *
     * @throws ValidationException  if the challenge expired/wasn't started, or the code is wrong
     */
    public function challengeTwoFactor(string $code): User
    {
        $session = request()->session();
        $userId = $session->get('two_factor_user_id');
        $expiresAt = $session->get('two_factor_expires_at');

        if ($userId === null || $expiresAt === null || now()->timestamp > $expiresAt) {
            $session->forget(['two_factor_user_id', 'two_factor_expires_at']);

            throw ValidationException::withMessages([
                'code' => ['This two-factor challenge has expired — log in again.'],
            ]);
        }

        /** @var User $user */
        $user = User::findOrFail($userId);

        $verified = $this->twoFactorService->verifyCode($user, $code)
            || $this->twoFactorService->verifyRecoveryCode($user, $code);

        if (! $verified) {
            throw ValidationException::withMessages(['code' => ['That code is invalid.']]);
        }

        $session->forget(['two_factor_user_id', 'two_factor_expires_at']);

        Auth::guard('web')->login($user);
        $session->regenerate();

        return $user->load('roles.permissions');
    }

    // register() and loginOrRegisterViaSocialite() moved to
    // CustomerAuthService/CustomerAccountService as part of the
    // customer/staff split — this service is staff-only ('web' guard)
    // from that point on. Staff accounts are created by an existing admin
    // via UserService::create(), never self-registered.

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
