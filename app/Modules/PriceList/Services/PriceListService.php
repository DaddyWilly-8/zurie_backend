<?php

namespace App\Modules\PriceList\Services;

use App\Modules\PriceList\Models\PriceList;
use App\Modules\PriceList\Models\PriceListItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class PriceListService
{
    /**
     * Most-specific-wins price resolution — see
     * Zurie_V2_Architecture_Design (2).md §33. Never queries Product
     * itself (Extensibility Constitution, Rule 2): the caller (Order/POS,
     * via ProductService) supplies `$defaultPrice`/`$defaultSalePrice` —
     * this service only decides whether an override applies on top of it.
     *
     * Resolution order: customer-specific active list -> outlet-specific
     * active list -> the caller's own default (== Product.price/sale_price,
     * i.e. the implicit "Default" list, which has no rows here at all).
     *
     * @return array{price: float, salePrice: float|null}
     */
    public function resolvePrice(
        int $productId,
        ?int $outletId,
        ?int $customerId,
        float $defaultPrice,
        ?float $defaultSalePrice,
    ): array {
        if ($customerId !== null) {
            $item = $this->findActiveItem($productId, customerId: $customerId);
            if ($item !== null) {
                return $this->toResult($item);
            }
        }

        if ($outletId !== null) {
            $item = $this->findActiveItem($productId, outletId: $outletId);
            if ($item !== null) {
                return $this->toResult($item);
            }
        }

        return ['price' => $defaultPrice, 'salePrice' => $defaultSalePrice];
    }

    private function findActiveItem(int $productId, ?int $outletId = null, ?int $customerId = null): ?PriceListItem
    {
        $today = Carbon::today()->toDateString();

        return PriceListItem::query()
            ->where('product_id', $productId)
            ->whereHas('priceList', function ($query) use ($outletId, $customerId, $today) {
                $query->where('is_active', true)
                    ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))
                    ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
                    ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today))
                    ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today));
            })
            ->first();
    }

    /**
     * @return array{price: float, salePrice: float|null}
     */
    private function toResult(PriceListItem $item): array
    {
        return [
            'price' => (float) $item->price,
            'salePrice' => $item->sale_price !== null ? (float) $item->sale_price : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  name, outletId?, customerId?, validFrom?, validTo?
     */
    public function create(array $data): PriceList
    {
        // Translated from camelCase to column names explicitly, rather
        // than array_merge()-ing $data in raw — see OutletService::
        // create()'s comment for why a raw merge is the wrong pattern.
        return PriceList::create([
            'is_default' => false,
            'is_active' => true,
            'name' => $data['name'],
            'outlet_id' => $data['outletId'] ?? null,
            'customer_id' => $data['customerId'] ?? null,
            'valid_from' => $data['validFrom'] ?? null,
            'valid_to' => $data['validTo'] ?? null,
        ]);
    }

    public function all(): Collection
    {
        return PriceList::query()->with('items')->orderBy('name')->get();
    }

    /**
     * Upsert — setting a price for a product already on this list replaces
     * it rather than creating a duplicate row (unique constraint on
     * [price_list_id, product_id] enforces this at the DB level too).
     */
    public function setItem(PriceList $priceList, int $productId, float $price, ?float $salePrice = null): PriceListItem
    {
        return PriceListItem::updateOrCreate(
            ['price_list_id' => $priceList->id, 'product_id' => $productId],
            ['price' => $price, 'sale_price' => $salePrice]
        );
    }
}
