<?php

namespace App\Modules\Activity\Listeners;

use Illuminate\Auth\Events\PasswordReset;

/**
 * Dispatched manually from AuthController::resetPassword() after
 * Password::reset() succeeds. The same request also saves the user's new
 * password directly ($user->forceFill(['password' => ...])->save()), which
 * would otherwise trigger User's own LogsActivity "updated" log too — User's
 * getActivitylogOptions() excludes password-only changes via
 * dontLogIfAttributesChangedOnly(['password']) specifically so this listener
 * is the one and only log entry for a password reset, not a duplicate.
 */
class LogPasswordReset
{
    public function handle(PasswordReset $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->event('password_reset')
            ->log("{$event->user->name} reset their password");
    }
}
