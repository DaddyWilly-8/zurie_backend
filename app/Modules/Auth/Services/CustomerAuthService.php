<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\CustomerAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Owns the 'customer' guard's session mechanics — the customer-guard
 * counterpart to AuthService, which now owns only the 'web' (staff)
 * guard. See CustomerAccount's docblock for why these two guards are
 * independent despite sharing one session cookie.
 */
class CustomerAuthService
{
    public function __construct(private readonly CustomerAccountService $customerAccountService) {}

    public function attempt(string $email, string $password): CustomerAccount
    {
        if (! Auth::guard('customer')->attempt(['email' => $email, 'password' => $password])) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        request()->session()->regenerate();

        /** @var CustomerAccount $account */
        $account = Auth::guard('customer')->user();

        return $account;
    }

    /**
     * @param  array<string, mixed>  $data  name, email, password, phone, whatsappNumber?
     */
    public function register(array $data): CustomerAccount
    {
        $account = $this->customerAccountService->register($data);

        Auth::guard('customer')->login($account);
        request()->session()->regenerate();

        return $account;
    }

    public function loginOrRegisterViaGoogle(string $email, ?string $name): CustomerAccount
    {
        $account = $this->customerAccountService->findOrCreateForSocialite($email, $name);

        Auth::guard('customer')->login($account);
        request()->session()->regenerate();

        return $account;
    }

    /**
     * Deliberately does NOT call session()->invalidate() the way
     * AuthService::logout() does — invalidate() wipes the ENTIRE session
     * payload, which would also silently kill an unrelated 'web' (staff)
     * login sharing the same browser session, exactly the cross-guard
     * contamination this split exists to prevent. regenerate() rotates
     * the session id (still defeats session fixation) without touching
     * any other guard's login state.
     */
    public function logout(): void
    {
        Auth::guard('customer')->logout();

        request()->session()->regenerate();
    }

    public function currentUser(CustomerAccount $account): CustomerAccount
    {
        return $account;
    }
}
