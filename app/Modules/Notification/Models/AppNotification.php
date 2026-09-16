<?php

namespace App\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Named AppNotification, not Notification — Laravel's own framework
 * already reserves `Illuminate\Notifications\Notification` and a
 * `notifications` table convention for its built-in notification channel
 * system (mail/database/broadcast); this is a simpler, purpose-built
 * in-app notification log, not that system, so it gets its own name to
 * avoid confusion with the framework class.
 */
#[Fillable(['user_id', 'type', 'message', 'read_at'])]
class AppNotification extends Model
{
    protected $table = 'notifications';

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}
