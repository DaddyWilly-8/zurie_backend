<?php

namespace App\Modules\Wishlist\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Wishlist\Requests\AddWishlistItemRequest;
use App\Modules\Wishlist\Resources\WishlistItemResource;
use App\Modules\Wishlist\Services\WishlistService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

/**
 * Self-service — always scoped to the authenticated user's own linked
 * Customer record, never an id read from the request, same rule as
 * AccountController.
 */
class WishlistController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly WishlistService $wishlistService,
        private readonly CustomerService $customerService,
    ) {}

    public function index(Request $request)
    {
        $customer = $this->customerService->findByUserId($request->user()->id);
        if ($customer === null) {
            return $this->ok([]);
        }

        return $this->ok(WishlistItemResource::collection($this->wishlistService->forCustomer($customer->id)));
    }

    public function store(AddWishlistItemRequest $request)
    {
        $customer = $this->customerService->findByUserId($request->user()->id);
        if ($customer === null) {
            return $this->fail('No customer profile is linked to this account.', 422);
        }

        $item = $this->wishlistService->add($customer->id, (int) $request->validated()['productId']);

        return $this->created(new WishlistItemResource($item));
    }

    public function destroy(Request $request, int $productId)
    {
        $customer = $this->customerService->findByUserId($request->user()->id);
        if ($customer !== null) {
            $this->wishlistService->remove($customer->id, $productId);
        }

        return $this->ok();
    }
}
