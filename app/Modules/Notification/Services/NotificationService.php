<?php

namespace App\Modules\Notification\Services;

use App\Modules\Notification\Models\AppNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * `$notifiable` is any Eloquent model — today only `User` (staff) or
 * `CustomerAccount` (customer), matched purely by class+id, no coupling
 * to either module's own logic (Extensibility Constitution Rule 2: this
 * stays a plain polymorphic reference, never a direct relation into
 * Auth's models).
 */
class NotificationService
{
    public function notify(Model $notifiable, string $type, string $message): AppNotification
    {
        return AppNotification::create([
            'notifiable_type' => $notifiable::class,
            'notifiable_id' => $notifiable->getKey(),
            'type' => $type,
            'message' => $message,
        ]);
    }

    public function paginateFor(Model $notifiable, int $page, int $pageSize): LengthAwarePaginator
    {
        return AppNotification::where('notifiable_type', $notifiable::class)
            ->where('notifiable_id', $notifiable->getKey())
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    public function unreadCountFor(Model $notifiable): int
    {
        return AppNotification::where('notifiable_type', $notifiable::class)
            ->where('notifiable_id', $notifiable->getKey())
            ->whereNull('read_at')
            ->count();
    }

    public function markRead(AppNotification $notification): AppNotification
    {
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return $notification;
    }

    public function belongsTo(AppNotification $notification, Model $notifiable): bool
    {
        return $notification->notifiable_type === $notifiable::class
            && (int) $notification->notifiable_id === (int) $notifiable->getKey();
    }
}
