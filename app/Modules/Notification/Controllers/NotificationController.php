<?php

namespace App\Modules\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Models\AppNotification;
use App\Modules\Notification\Resources\NotificationResource;
use App\Modules\Notification\Services\NotificationService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

/**
 * Self-service — always scoped to $request->user()->id, same rule as
 * AccountController/WishlistController. Any authenticated user (admin,
 * staff, or customer) can have notifications; not customer-specific.
 */
class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly NotificationService $notificationService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));

        $notifications = $this->notificationService->paginateForUser($request->user()->id, $page, $pageSize);

        return $this->paginated(
            NotificationResource::collection($notifications->items()),
            [
                'count' => $notifications->total(),
                'page' => $notifications->currentPage(),
                'pageSize' => $notifications->perPage(),
                'unread' => $this->notificationService->unreadCountForUser($request->user()->id),
            ]
        );
    }

    /**
     * Ownership check, not a permission gate — a notification belongs to
     * exactly one user, and route-model binding alone doesn't scope by
     * the authenticated session, so this is enforced explicitly here.
     */
    public function markRead(Request $request, AppNotification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return $this->fail('This action is unauthorized.', 403);
        }

        return $this->ok(new NotificationResource($this->notificationService->markRead($notification)));
    }
}
