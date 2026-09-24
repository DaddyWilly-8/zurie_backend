<?php

namespace App\Modules\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Requests\SubscribeNewsletterRequest;
use App\Modules\Notification\Services\NewsletterService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly NewsletterService $newsletterService) {}

    /**
     * POST /newsletter — public, the storefront footer's signup form.
     */
    public function subscribe(SubscribeNewsletterRequest $request)
    {
        $this->newsletterService->subscribe($request->validated()['email']);

        return $this->ok(['subscribed' => true]);
    }

    /**
     * GET /admin/newsletter-subscribers — for exporting/contacting the list.
     */
    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 50));
        $subscribers = $this->newsletterService->paginate($request->query('search'), $page, $pageSize);

        return $this->paginated(
            collect($subscribers->items())->map(fn ($subscriber) => [
                'id' => $subscriber->id,
                'email' => $subscriber->email,
                'createdAt' => $subscriber->created_at?->toISOString(),
            ]),
            ['count' => $subscribers->total(), 'page' => $subscribers->currentPage(), 'pageSize' => $subscribers->perPage()]
        );
    }
}
