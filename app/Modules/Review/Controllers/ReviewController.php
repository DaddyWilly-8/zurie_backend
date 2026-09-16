<?php

namespace App\Modules\Review\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Review\Models\ProductReview;
use App\Modules\Review\Requests\StoreReviewRequest;
use App\Modules\Review\Requests\UpdateReviewStatusRequest;
use App\Modules\Review\Resources\ReviewResource;
use App\Modules\Review\Services\ReviewService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReviewService $reviewService,
        private readonly CustomerService $customerService,
    ) {}

    /**
     * GET /products/{productId}/reviews — public, approved only.
     */
    public function index(int $productId)
    {
        return $this->ok(ReviewResource::collection($this->reviewService->approvedForProduct($productId)));
    }

    /**
     * POST /account/reviews — self-service, requires a linked Customer
     * record (same rule as WishlistController).
     */
    public function store(StoreReviewRequest $request)
    {
        $customer = $this->customerService->findByUserId($request->user()->id);
        if ($customer === null) {
            return $this->fail('No customer profile is linked to this account.', 422);
        }

        $review = $this->reviewService->submit($customer->id, $request->validated());

        return $this->created(new ReviewResource($review));
    }

    public function adminIndex(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));
        $filters = $request->only(['status']);

        $reviews = $this->reviewService->paginateAdmin($filters, $page, $pageSize);

        return $this->paginated(
            ReviewResource::collection($reviews->items()),
            ['count' => $reviews->total(), 'page' => $reviews->currentPage(), 'pageSize' => $reviews->perPage()]
        );
    }

    public function updateStatus(UpdateReviewStatusRequest $request, ProductReview $review)
    {
        $review = $this->reviewService->updateStatus($review, $request->validated()['status']);

        return $this->ok(new ReviewResource($review));
    }
}
