<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\CustomerForgotPasswordRequest;
use App\Modules\Auth\Requests\CustomerLoginRequest;
use App\Modules\Auth\Requests\CustomerRegisterRequest;
use App\Modules\Auth\Requests\CustomerResetPasswordRequest;
use App\Modules\Auth\Resources\CustomerAuthResource;
use App\Modules\Auth\Services\CustomerAccountService;
use App\Modules\Auth\Services\CustomerAuthService;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Laravel\Socialite\Facades\Socialite;

/**
 * Storefront-only counterpart to AuthController (staff, 'web' guard) —
 * see CustomerAccount's docblock for why the two are kept fully separate
 * despite sharing one session cookie.
 */
class CustomerAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CustomerAuthService $customerAuthService,
        private readonly CustomerAccountService $customerAccountService,
    ) {}

    public function login(CustomerLoginRequest $request)
    {
        $credentials = $request->validated();

        $account = $this->customerAuthService->attempt($credentials['email'], $credentials['password']);

        return $this->ok(new CustomerAuthResource($account));
    }

    /**
     * Never required to complete a purchase — POST /orders (checkout) has
     * no auth requirement and works standalone as guest checkout, same as
     * before the split.
     */
    public function register(CustomerRegisterRequest $request)
    {
        $account = $this->customerAuthService->register($request->validated());

        return $this->created(new CustomerAuthResource($account));
    }

    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        try {
            $socialiteUser = Socialite::driver('google')->user();
            $this->customerAuthService->loginOrRegisterViaGoogle(
                $socialiteUser->getEmail(),
                $socialiteUser->getName(),
            );
        } catch (\Throwable $exception) {
            Log::warning('Google OAuth callback failed (customer)', [
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
        $this->customerAuthService->logout();

        return $this->ok();
    }

    public function user(Request $request)
    {
        return $this->ok(new CustomerAuthResource($request->user('customer')));
    }

    public function forgotPassword(CustomerForgotPasswordRequest $request)
    {
        Password::broker('customer_accounts')->sendResetLink($request->only('email'));

        return $this->ok();
    }

    public function resetPassword(CustomerResetPasswordRequest $request)
    {
        Password::broker('customer_accounts')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($account, string $password): void {
                $account->forceFill(['password' => $password])->save();

                $this->customerAccountService->invalidateSessionsFor($account);

                event(new PasswordReset($account));
            }
        );

        return $this->ok();
    }
}
