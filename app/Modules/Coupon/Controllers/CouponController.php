<?php

namespace App\Modules\Coupon\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coupon\Models\Coupon;
use App\Modules\Coupon\Requests\PreviewCouponRequest;
use App\Modules\Coupon\Requests\StoreCouponRequest;
use App\Modules\Coupon\Requests\UpdateCouponActiveRequest;
use App\Modules\Coupon\Resources\CouponResource;
use App\Modules\Coupon\Services\CouponService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CouponService $couponService) {}

    /**
     * POST /coupons/preview — public, no auth. Validates a code against a
     * subtotal and shows the discount it would apply, without redeeming
     * it — lets the frontend show "Coupon applied: -10,000" before the
     * customer commits to checkout. The actual application + redemption
     * only happens inside OrderService::checkout()/posSale().
     */
    public function preview(PreviewCouponRequest $request)
    {
        $data = $request->validated();
        $coupon = $this->couponService->validate($data['code'], (float) $data['subtotal']);
        $discount = $this->couponService->calculateDiscount($coupon, (float) $data['subtotal']);

        return $this->ok([
            'valid' => true,
            'discountAmount' => $discount,
        ]);
    }

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = $this->pageSize($request);

        $coupons = $this->couponService->paginateAdmin($page, $pageSize);

        return $this->paginated(
            CouponResource::collection($coupons->items()),
            ['count' => $coupons->total(), 'page' => $coupons->currentPage(), 'pageSize' => $coupons->perPage()]
        );
    }

    public function store(StoreCouponRequest $request)
    {
        $coupon = $this->couponService->create($request->validated());

        return $this->created(new CouponResource($coupon));
    }

    public function updateActive(UpdateCouponActiveRequest $request, Coupon $coupon)
    {
        $coupon = $this->couponService->setActive($coupon, $request->validated()['isActive']);

        return $this->ok(new CouponResource($coupon));
    }
}
