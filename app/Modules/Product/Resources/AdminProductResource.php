<?php

namespace App\Modules\Product\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /admin/products/{id} — the only resource that includes buyingPrice.
 * Kept as a fully separate class from PublicProductResource (rather than a
 * conditional field) so it's structurally impossible to leak cost data
 * through a stray `if` — per zurie-backend-implementation-spec.md §4.
 */
class AdminProductResource extends JsonResource
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
            'description' => $this->description,
            'shortDescription' => $this->short_description,
            'categoryId' => $this->category_id,
            'sku' => $this->sku,
            'measurementUnitId' => $this->measurement_unit_id,
            'vatExempted' => (bool) $this->vat_exempted,
            'buyingPrice' => (float) $this->buying_price,
            'price' => (float) $this->price,
            'salePrice' => $this->sale_price !== null ? (float) $this->sale_price : null,
            'material' => $this->material,
            'specifications' => $this->specifications ?? [],
            'status' => $this->status,
            'featured' => $this->featured,
            'bestSeller' => $this->best_seller,
            'newArrival' => $this->new_arrival,
            'colors' => $this->whenLoaded('colors', fn() => $this->colors->map(fn($color) => [
                'name' => $color->name,
                'hex' => $color->hex,
            ])),
            'sizes' => $this->whenLoaded('sizes', fn() => $this->sizes->pluck('label')),
            'imageUrls' => $this->whenLoaded('images', fn() => $this->images->pluck('url')),
            // Same data as imageUrls, but with the id needed to call
            // DELETE /products/{id}/images/{imageId} — imageUrls stays as
            // plain strings for anything that only needs to render images,
            // this is additive for the admin image-management UI specifically.
            'images' => $this->whenLoaded('images', fn() => $this->images->map(fn($image) => [
                'id' => $image->id,
                'url' => $image->url,
                'sortOrder' => $image->sort_order,
            ])),
            'stockStatus' => $this->stock_status ?? null,
            'quantity' => $this->quantity ?? 0,
            'seoTitle' => $this->seo_title,
            'seoDescription' => $this->seo_description,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
