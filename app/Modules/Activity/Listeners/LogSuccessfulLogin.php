<?php

namespace App\Modules\Activity\Listeners;

use Illuminate\Auth\Events\Login;

/**
 * Auth::guard('web')->attempt() (called from AuthService::attempt()) fires
 * this natively on success — no change needed in Auth itself. Registered
 * explicitly in ActivityModuleServiceProvider::boot(), same as every other
 * cross-module listener in this app (listeners outside app/Listeners aren't
 * auto-discovered).
 */
class LogSuccessfulLogin
{
    public function handle(Login $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->event('login')
            ->log("{$event->user->name} logged in");
    }
}
