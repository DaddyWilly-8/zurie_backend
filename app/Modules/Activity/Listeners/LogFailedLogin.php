<?php

namespace App\Modules\Activity\Listeners;

use Illuminate\Auth\Events\Failed;

/**
 * $event->user is whoever the attempter matched by email, if anyone — used
 * only to know whether the account exists, never as the causer (a failed
 * login isn't an action that account performed). $event->credentials
 * carries the raw password; never touched here, only the email is read.
 */
class LogFailedLogin
{
    public function handle(Failed $event): void
    {
        $email = $event->credentials['email'] ?? 'unknown';

        activity('auth')
            ->causedByAnonymous()
            ->event('login_failed')
            ->log("Failed login attempt for {$email}");
    }
}
