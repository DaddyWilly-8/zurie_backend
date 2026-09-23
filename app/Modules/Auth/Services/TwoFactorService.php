<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication (RFC 6238 — the same standard Google
 * Authenticator, Authy, and 1Password all implement; verified offline,
 * no third-party network call, no SMS cost/interception risk). Optional
 * per account today — not yet mandated for any role — see
 * docs/ARCHITECTURE_GUIDE.md §14b "Operations" for the enforcement
 * decision still to be made.
 *
 * Setup is two steps, never one: enable() generates a secret but does NOT
 * protect the account yet (two_factor_confirmed_at stays null) — confirm()
 * only flips that once the user proves they actually scanned it and can
 * produce a valid code, so a user who abandons setup mid-way is never
 * silently locked out by a secret they never actually saved into their
 * authenticator app.
 */
class TwoFactorService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * @return array{secret: string, qrCodeUrl: string}
     */
    public function enable(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey();

        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_recovery_codes = null;
        $user->save();

        return [
            'secret' => $secret,
            'qrCodeUrl' => $this->google2fa->getQRCodeUrl(
                Config::get('app.name', 'Zurie'),
                $user->email,
                $secret,
            ),
        ];
    }

    /**
     * Verifies the first real code from the user's authenticator app and,
     * only then, actually activates 2FA on the account. Issues one set of
     * recovery codes — shown to the user exactly once, here — for the
     * "lost my phone" case; each is single-use (consumed by
     * verifyRecoveryCode()) and this call always replaces any previous
     * set, so re-confirming after a lost-device recovery reissues fresh
     * codes rather than leaving old, possibly-exposed ones valid.
     *
     * @return array<int, string>  the plaintext recovery codes — capture these in the response, they can never be shown again
     *
     * @throws ValidationException  if no secret is pending, or the code doesn't verify
     */
    public function confirm(User $user, string $code): array
    {
        if ($user->two_factor_secret === null) {
            throw ValidationException::withMessages(['code' => 'No two-factor setup is in progress for this account.']);
        }

        if (! $this->google2fa->verifyKey($user->two_factor_secret, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is invalid or has expired.']);
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->two_factor_confirmed_at = now();
        $user->two_factor_recovery_codes = $recoveryCodes;
        $user->save();

        return $recoveryCodes;
    }

    public function disable(User $user): void
    {
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();
    }

    /**
     * A 30-second-window TOTP code from the authenticator app.
     */
    public function verifyCode(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        return $this->google2fa->verifyKey($user->two_factor_secret, $code);
    }

    /**
     * Single-use fallback for a lost device — consumes the code on
     * success so it can never be replayed, and persists immediately
     * (not deferred to the caller) so a crash between verification and
     * login can't leave a "used" code still marked valid.
     */
    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $normalized = strtoupper(trim($code));

        if (! in_array($normalized, $codes, true)) {
            return false;
        }

        $user->two_factor_recovery_codes = array_values(array_diff($codes, [$normalized]));
        $user->save();

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => strtoupper(Str::random(4).'-'.Str::random(4)))
            ->all();
    }
}
