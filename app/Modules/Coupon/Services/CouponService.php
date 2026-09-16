<?php

namespace App\Modules\Coupon\Services;

use App\Modules\Coupon\Exceptions\InvalidCouponException;
use App\Modules\Coupon\Models\Coupon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class CouponService
{
    /**
     * Every reason a code can't be used, checked in one place so
     * OrderService never has to re-derive this logic. Called with the
     * order's subtotal (before discount) since min_order_amount is a
     * pre-discount threshold.
     *
     * @throws InvalidCouponException
     */
    public function validate(string $code, float $subtotal): Coupon
    {
        $coupon = Coupon::where('code', $code)->first();
        if ($coupon === null) {
            throw new InvalidCouponException("Coupon code '{$code}' does not exist.");
        }

        if (! $coupon->is_active) {
            throw new InvalidCouponException("Coupon '{$code}' is no longer active.");
        }

        $today = Carbon::today();
        if ($coupon->valid_from !== null && $today->lt($coupon->valid_from)) {
            throw new InvalidCouponException("Coupon '{$code}' is not active yet.");
        }
        if ($coupon->valid_to !== null && $today->gt($coupon->valid_to)) {
            throw new InvalidCouponException("Coupon '{$code}' has expired.");
        }

        if ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses) {
            throw new InvalidCouponException("Coupon '{$code}' has reached its usage limit.");
        }

        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            throw new InvalidCouponException(
                "Coupon '{$code}' requires a minimum order of {$coupon->min_order_amount}."
            );
        }

        return $coupon;
    }

    /**
     * Percentage discounts are a fraction of the subtotal; fixed discounts
     * are capped at the subtotal — a coupon can never make an order total
     * negative.
     */
    public function calculateDiscount(Coupon $coupon, float $subtotal): float
    {
        $discount = $coupon->type === 'percentage'
            ? $subtotal * ((float) $coupon->value / 100)
            : (float) $coupon->value;

        return min($discount, $subtotal);
    }

    /**
     * Called once per successful checkout that applied this coupon — must
     * be called from inside the same transaction as the order creation it
     * belongs to, so a failed checkout never burns a use.
     */
    public function redeem(Coupon $coupon): void
    {
        $coupon->increment('used_count');
    }

    /**
     * Reverses redeem() — called by OrderService::cancel() when the
     * cancelled order had used a coupon (Order::coupon_id), so a
     * maxUses-limited coupon isn't permanently burned by an order that
     * never actually happened. Floored at 0 via decrement()'s own guard
     * (MySQL UNSIGNED-style floor isn't assumed; used_count is a plain
     * int column, so an already-corrected/edge-case 0 stays 0 rather than
     * going negative) — looked up by id, not passed as a hydrated model,
     * since the only caller has just an id (Order::coupon_id), not a
     * Coupon instance. Silently no-ops if the coupon was deleted since —
     * cancelling an old order should never fail because of that.
     */
    public function unredeemById(int $couponId): void
    {
        $coupon = Coupon::find($couponId);
        if ($coupon === null) {
            return;
        }

        if ($coupon->used_count > 0) {
            $coupon->decrement('used_count');
        }
    }

    /**
     * @param  array<string, mixed>  $data  code, type, value, minOrderAmount?, maxUses?, validFrom?, validTo?
     */
    public function create(array $data): Coupon
    {
        // used_count/is_active set explicitly rather than left to the DB
        // column default — Eloquent's create() doesn't reload DB-applied
        // defaults into the in-memory model (same bug class as
        // CostCenterService::create() had — see that fix's commit).
        return Coupon::create([
            'code' => strtoupper($data['code']),
            'type' => $data['type'],
            'value' => $data['value'],
            'min_order_amount' => $data['minOrderAmount'] ?? null,
            'max_uses' => $data['maxUses'] ?? null,
            'used_count' => 0,
            'valid_from' => $data['validFrom'] ?? null,
            'valid_to' => $data['validTo'] ?? null,
            'is_active' => true,
        ]);
    }

    public function paginateAdmin(int $page, int $pageSize): LengthAwarePaginator
    {
        return Coupon::query()->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    public function setActive(Coupon $coupon, bool $isActive): Coupon
    {
        $coupon->update(['is_active' => $isActive]);

        return $coupon;
    }
}
