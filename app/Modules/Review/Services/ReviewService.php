<?php

namespace App\Modules\Review\Services;

use App\Modules\Customer\Services\CustomerService;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Product\Services\ProductService;
use App\Modules\Review\Models\ProductReview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ReviewService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ProductService $productService,
        private readonly CustomerService $customerService,
    ) {}

    /**
     * Sets product_name / customer_name (not persisted) on each review for
     * ReviewResource — batched, and through the owning modules' Services.
     *
     * @param  iterable<ProductReview>  $reviews
     */
    public function attachNames(iterable $reviews): void
    {
        $reviews = collect($reviews);
        $products = $this->productService->namesFor($reviews->pluck('product_id')->unique()->values()->all());
        $customers = $this->customerService->namesFor($reviews->pluck('customer_id')->unique()->values()->all());

        foreach ($reviews as $review) {
            $review->setAttribute('product_name', $products[$review->product_id] ?? null);
            $review->setAttribute('customer_name', $customers[$review->customer_id] ?? null);
        }
    }

    /**
     * Public product-page reviews — approved only. A pending/rejected
     * review is invisible to everyone except the admin moderation queue.
     */
    public function approvedForProduct(int $productId): Collection
    {
        return ProductReview::where('product_id', $productId)
            ->where('status', 'approved')
            ->latest()
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data  productId, rating, comment?
     */
    public function submit(int $customerId, array $data): ProductReview
    {
        $review = ProductReview::create([
            'product_id' => $data['productId'],
            'customer_id' => $customerId,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
            'status' => 'pending',
        ]);

        $this->notificationService->notifyStaff(
            'review_manage',
            'review_pending',
            "New {$review->rating}-star review awaiting approval (product #{$review->product_id}).",
        );

        return $review;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateAdmin(array $filters, int $page, int $pageSize): LengthAwarePaginator
    {
        $query = ProductReview::query();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    public function updateStatus(ProductReview $review, string $status): ProductReview
    {
        $review->status = $status;
        $review->save();

        return $review;
    }
}
