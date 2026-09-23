<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Requests\ResetPasswordRequest;
use App\Modules\Auth\Requests\TwoFactorChallengeRequest;
use App\Modules\Auth\Resources\AuthUserResource;
use App\Modules\Auth\Services\AuthService;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * Staff-only ('web' guard) from the customer/staff split onward — no
 * self-registration and no Google login here; staff accounts are created
 * by an existing admin (UserService::create()). See
 * CustomerAuthController for the storefront equivalent.
 */
class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuthService $authService) {}

    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        $result = $this->authService->attempt($credentials['email'], $credentials['password']);

        if ($result['status'] === 'two_factor_required') {
            return $this->ok(['twoFactorRequired' => true]);
        }

        return $this->ok(new AuthUserResource($result['user']));
    }

    /**
     * POST /auth/two-factor/challenge — completes a login that
     * AuthService::attempt() left pending because the account has 2FA
     * confirmed. Not behind auth:sanctum (the caller isn't logged in yet)
     * — AuthService::challengeTwoFactor() authorizes purely from the
     * short-lived session marker attempt() left, which throttle:two-factor
     * also keys on.
     */
    public function twoFactorChallenge(TwoFactorChallengeRequest $request)
    {
        $user = $this->authService->challengeTwoFactor($request->validated()['code']);

        return $this->ok(new AuthUserResource($user));
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
