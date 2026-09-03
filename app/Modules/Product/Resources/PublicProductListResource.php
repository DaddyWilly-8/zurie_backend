<?php

namespace App\Modules\Product\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /products — list item. Never includes buyingPrice; see AdminProductResource
 * for the admin-only counterpart (two separate resource classes, not one
 * conditional field, per zurie-backend-implementation-spec.md §4).
 */
class PublicProductListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'price' => (float) $this->price,
            'salePrice' => $this->sale_price !== null ? (float) $this->sale_price : null,
            'featuredImageUrl' => $this->whenLoaded('images', fn() => $this->images->first()?->url),
            'category' => $this->whenLoaded('category', fn() => new CategoryResource($this->category)),
            // Resolved from the Inventory module's batched service call — see
            // ProductService::attachStockStatuses(). Null until Inventory exists.
            'stockStatus' => $this->stock_status ?? null,
        ];
    }
}
