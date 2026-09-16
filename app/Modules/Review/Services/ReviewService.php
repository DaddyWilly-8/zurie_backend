<?php

namespace App\Modules\Review\Services;

use App\Modules\Review\Models\ProductReview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ReviewService
{
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
        return ProductReview::create([
            'product_id' => $data['productId'],
            'customer_id' => $customerId,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
            'status' => 'pending',
        ]);
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
