<?php

namespace App\Modules\Notification\Services;

use App\Modules\Notification\Models\NewsletterSubscriber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class NewsletterService
{
    /**
     * Idempotent: subscribing an address that's already on the list is a
     * silent success, so the endpoint never reveals who is subscribed.
     */
    public function subscribe(string $email): void
    {
        NewsletterSubscriber::firstOrCreate(['email' => Str::lower(trim($email))]);
    }

    public function paginate(?string $search, int $page, int $pageSize): LengthAwarePaginator
    {
        return NewsletterSubscriber::query()
            ->when($search, fn ($query) => $query->where('email', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }
}
