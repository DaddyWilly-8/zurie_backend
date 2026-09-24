<?php

namespace App\Modules\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Models\AppNotification;
use App\Modules\Notification\Resources\NotificationResource;
use App\Modules\Notification\Services\NotificationService;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * One controller for both audiences: storefront customers (the 'customer'
 * guard, /account/notifications) and staff (the 'web' guard,
 * /admin/notifications). Every action only ever touches the notifications
 * of whoever is logged in on that guard.
 */
class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly NotificationService $notificationService) {}

    public function index(Request $request)
    {
        return $this->listFor($request, $request->user('customer'));
    }

    public function markRead(Request $request, AppNotification $notification)
    {
        return $this->markReadFor($request->user('customer'), $notification);
    }

    public function markAllRead(Request $request)
    {
        return $this->ok(['updated' => $this->notificationService->markAllReadFor($request->user('customer'))]);
    }

    public function staffIndex(Request $request)
    {
        return $this->listFor($request, $request->user());
    }

    public function staffMarkRead(Request $request, AppNotification $notification)
    {
        return $this->markReadFor($request->user(), $notification);
    }

    public function staffMarkAllRead(Request $request)
    {
        return $this->ok(['updated' => $this->notificationService->markAllReadFor($request->user())]);
    }

    private function listFor(Request $request, Model $notifiable)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = $this->pageSize($request);

        $notifications = $this->notificationService->paginateFor($notifiable, $page, $pageSize);

        return $this->paginated(
            NotificationResource::collection($notifications->items()),
            [
                'count' => $notifications->total(),
                'page' => $notifications->currentPage(),
                'pageSize' => $notifications->perPage(),
                'unread' => $this->notificationService->unreadCountFor($notifiable),
            ]
        );
    }

    private function markReadFor(Model $notifiable, AppNotification $notification)
    {
        if (! $this->notificationService->belongsTo($notification, $notifiable)) {
            return $this->fail('This action is unauthorized.', 403);
        }

        return $this->ok(new NotificationResource($this->notificationService->markRead($notification)));
    }
}
