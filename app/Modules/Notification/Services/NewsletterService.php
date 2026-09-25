<?php

namespace App\Modules\Notification\Services;

use App\Modules\Notification\Models\NewsletterSubscriber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class NewsletterService
{
    public function __construct(private readonly NotificationService $notificationService) {}

    /**
     * Idempotent: subscribing an address that's already on the list is a
     * silent success, so the endpoint never reveals who is subscribed.
     * Staff notification only fires for a genuinely new subscriber
     * (firstOrCreate's wasRecentlyCreated) — re-submitting an existing
     * address was previously silent to the caller and should stay
     * silent to staff too, or every accidental double-submit would spam
     * the notification bell.
     */
    public function subscribe(string $email): void
    {
        $subscriber = NewsletterSubscriber::firstOrCreate(['email' => Str::lower(trim($email))]);

        if ($subscriber->wasRecentlyCreated) {
            $this->notificationService->notifyStaff(
                'customer_view',
                'new_newsletter_subscriber',
                "New newsletter subscriber: {$subscriber->email}",
            );
        }
    }

    public function paginate(?string $search, int $page, int $pageSize): LengthAwarePaginator
    {
        return NewsletterSubscriber::query()
            ->when($search, fn ($query) => $query->where('email', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }
}
