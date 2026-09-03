<?php

namespace App\Modules\Activity\Listeners;

use Illuminate\Auth\Events\Logout;

class LogLogout
{
    public function handle(Logout $event): void
    {
        if ($event->user === null) {
            return;
        }

        activity('auth')
            ->causedBy($event->user)
            ->event('logout')
            ->log("{$event->user->name} logged out");
    }
}
