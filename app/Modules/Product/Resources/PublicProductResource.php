<?php

namespace App\Modules\Product\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /products/{slug} — full public detail. Never includes buyingPrice.
 */
class PublicProductResource extends JsonResource
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
            'category' => $this->whenLoaded('category', fn() => new CategoryResource($this->category)),
            'price' => (float) $this->price,
            'salePrice' => $this->sale_price !== null ? (float) $this->sale_price : null,
            'material' => $this->material,
            'colors' => $this->whenLoaded('colors', fn() => $this->colors->map(fn($color) => [
                'name' => $color->name,
                'hex' => $color->hex,
            ])),
            'sizes' => $this->whenLoaded('sizes', fn() => $this->sizes->pluck('label')),
            'specifications' => $this->specifications ?? [],
            'imageUrls' => $this->whenLoaded('images', fn() => $this->images->pluck('url')),
            'featured' => $this->featured,
            'bestSeller' => $this->best_seller,
            'newArrival' => $this->new_arrival,
            // See PublicProductListResource — resolved via Inventory's service once that module exists.
            'stockStatus' => $this->stock_status ?? null,
            'seoTitle' => $this->seo_title,
            'seoDescription' => $this->seo_description,
        ];
    }
}
