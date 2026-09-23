<?php

namespace App\Modules\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Models\AppNotification;
use App\Modules\Notification\Resources\NotificationResource;
use App\Modules\Notification\Services\NotificationService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

/**
 * Self-service, customer-only — always scoped to $request->user('customer'),
 * never an id from the request (same rule as AccountController). Only
 * customers have a notification-reading UI today; if a future staff
 * notification feed is built, that's a separate controller resolving
 * $request->user() ('web' guard) instead, not a shared one — see
 * NotificationService's own polymorphic design for why the data model
 * already supports both.
 */
class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly NotificationService $notificationService) {}

    public function index(Request $request)
    {
        $customer = $request->user('customer');
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));

        $notifications = $this->notificationService->paginateFor($customer, $page, $pageSize);

        return $this->paginated(
            NotificationResource::collection($notifications->items()),
            [
                'count' => $notifications->total(),
                'page' => $notifications->currentPage(),
                'pageSize' => $notifications->perPage(),
                'unread' => $this->notificationService->unreadCountFor($customer),
            ]
        );
    }

    /**
     * Ownership check, not a permission gate — a notification belongs to
     * exactly one notifiable, and route-model binding alone doesn't scope
     * by the authenticated session, so this is enforced explicitly here.
     */
    public function markRead(Request $request, AppNotification $notification)
    {
        if (! $this->notificationService->belongsTo($notification, $request->user('customer'))) {
            return $this->fail('This action is unauthorized.', 403);
        }

        return $this->ok(new NotificationResource($this->notificationService->markRead($notification)));
    }
}
