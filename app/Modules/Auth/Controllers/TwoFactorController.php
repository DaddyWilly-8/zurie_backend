<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\TwoFactorConfirmRequest;
use App\Modules\Auth\Requests\TwoFactorDisableRequest;
use App\Modules\Auth\Services\TwoFactorService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Self-service 2FA management for the currently authenticated user only
 * — same "acts on $request->user(), never an id from the request" rule
 * as AccountController, so one account can never enable/disable another's
 * 2FA. The login-time challenge (POST /auth/two-factor/challenge, for an
 * account that already has 2FA confirmed) lives on AuthController instead,
 * since the caller there isn't authenticated yet.
 */
class TwoFactorController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TwoFactorService $twoFactorService) {}

    public function status(Request $request)
    {
        return $this->ok(['enabled' => $request->user()->hasTwoFactorEnabled()]);
    }

    /**
     * Step 1 of 2 — generates a secret and returns it plus a QR code URI
     * to scan. Does NOT protect the account yet; see TwoFactorService::
     * enable()'s docblock. Safe to call again before confirming (e.g. the
     * user's authenticator app QR scan failed) — it simply issues a fresh
     * secret, replacing the unconfirmed one.
     */
    public function enable(Request $request)
    {
        $result = $this->twoFactorService->enable($request->user());

        return $this->ok($result);
    }

    /**
     * Step 2 of 2 — proves the user actually saved the secret into their
     * authenticator app, then activates 2FA and returns the one-time
     * recovery codes. The frontend must show these to the user
     * immediately and durably (e.g. force an explicit "I've saved these"
     * acknowledgement) — this is the only response that will ever contain
     * them in plaintext.
     */
    public function confirm(TwoFactorConfirmRequest $request)
    {
        $recoveryCodes = $this->twoFactorService->confirm($request->user(), $request->validated()['code']);

        return $this->ok(['recoveryCodes' => $recoveryCodes]);
    }

    public function disable(TwoFactorDisableRequest $request)
    {
        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->validated()['password'],
        ])) {
            throw ValidationException::withMessages(['password' => ['That password is incorrect.']]);
        }

        $this->twoFactorService->disable($request->user());

        return $this->ok(['enabled' => false]);
    }
}
