<?php

namespace App\Modules\Notification\Services;

use App\Modules\Notification\Models\AppNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class NotificationService
{
    public function notify(int $userId, string $type, string $message): AppNotification
    {
        return AppNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'message' => $message,
        ]);
    }

    public function paginateForUser(int $userId, int $page, int $pageSize): LengthAwarePaginator
    {
        return AppNotification::where('user_id', $userId)
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    public function unreadCountForUser(int $userId): int
    {
        return AppNotification::where('user_id', $userId)->whereNull('read_at')->count();
    }

    public function markRead(AppNotification $notification): AppNotification
    {
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return $notification;
    }
}
