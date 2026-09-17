<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Requests\RegisterRequest;
use App\Modules\Auth\Requests\ResetPasswordRequest;
use App\Modules\Auth\Resources\AuthUserResource;
use App\Modules\Auth\Services\AuthService;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuthService $authService) {}

    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        $user = $this->authService->attempt($credentials['email'], $credentials['password']);

        return $this->ok(new AuthUserResource($user));
    }

    /**
     * Public storefront signup — see AuthService::register(). Never
     * required to complete a purchase; POST /orders (checkout) has no
     * auth:sanctum requirement and works standalone as guest checkout.
     */
    public function register(RegisterRequest $request)
    {
        $user = $this->authService->register($request->validated());

        return $this->created(new AuthUserResource($user));
    }

    /**
     * GET /auth/google/redirect — a full browser navigation (not an XHR:
     * the caller is a plain <a href> in the frontend, never apiClient),
     * since establishing the session cookie at the end of this round trip
     * only works if the browser itself follows the whole redirect chain.
     * See AuthController::handleGoogleCallback() and AuthService::
     * loginOrRegisterViaSocialite().
     */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * GET /auth/google/callback — Google lands the browser here directly
     * (it's the configured GOOGLE_REDIRECT_URI), not the frontend. Ends by
     * bouncing the now-authenticated browser on to the frontend, session
     * cookie already set, rather than returning JSON — there's no XHR
     * caller waiting on this response to parse.
     */
    public function handleGoogleCallback()
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        try {
            $socialiteUser = Socialite::driver('google')->user();
            $this->authService->loginOrRegisterViaSocialite('google', $socialiteUser);
        } catch (\Throwable $exception) {
            // Logs the exception class too, not just getMessage() — some
            // exception types (Socialite's InvalidStateException among
            // them) are thrown with a deliberately empty message, which
            // made an earlier version of this log line useless for telling
            // "session/state mismatch" apart from any other failure mode.
            Log::warning('Google OAuth callback failed', [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return redirect("{$frontendUrl}/login?error=google_failed");
        }

        return redirect("{$frontendUrl}/account");
    }

    public function logout()
    {
        $this->authService->logout();

        return $this->ok();
    }

    public function user(Request $request)
    {
        $user = $this->authService->currentUser($request->user());

        return $this->ok(new AuthUserResource($user));
    }

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        Password::sendResetLink($request->only('email'));

        // Always respond success regardless of whether the email exists —
        // avoids leaking which addresses are registered.
        return $this->ok();
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password): void {
                $user->forceFill(['password' => $password])->save();

                // Any session established before this reset — including a
                // session an attacker who had the old password was using —
                // is now dead, not just idled-out on its next 120-minute
                // timeout. See AuthService::invalidateSessionsFor().
                $this->authService->invalidateSessionsFor($user);

                event(new PasswordReset($user));
            }
        );

        return $this->ok();
    }
}
