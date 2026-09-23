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
 *
 * `notifiable_type`/`notifiable_id` (polymorphic, not a plain `user_id`)
 * since the customer/staff split — a recipient can be either a staff
 * `User` or a customer `CustomerAccount`, two different tables with
 * overlapping integer ids. See the migration that introduced this for
 * the full reasoning.
 */
#[Fillable(['notifiable_type', 'notifiable_id', 'type', 'message', 'read_at'])]
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
