<?php

namespace App\Modules\Product\Services;

use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Media\Services\MediaService;
use App\Modules\Product\Events\ProductCreated;
use App\Modules\Product\Events\ProductDeleted;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductImage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly MediaService $mediaService,
    ) {}

    /**
     * Lightweight existence check for other modules validating a
     * cross-module product_id reference (e.g. Inventory, Order) without
     * pulling the full product + relations just to confirm it exists.
     */
    public function exists(int $id): bool
    {
        return Product::query()->whereKey($id)->exists();
    }

    /**
     * Dashboard's `totalProducts` stat — every product row regardless of
     * `status` (draft/published/archived all count). One of several
     * single-purpose count methods each module owning a dashboard stat
     * exposes; DashboardService::overview() just calls this and assembles
     * results, never queries `products` directly itself — same
     * cross-module boundary rule as everywhere else.
     */
    public function countTotal(): int
    {
        return Product::query()->count();
    }

    /**
     * Batched name lookup for other modules' cross-module read pattern
     * (e.g. Report combining Inventory's low-stock product ids with a
     * human-readable name) — same "one query, not one per product" shape
     * as InventoryService::getStatusForProducts().
     *
     * @param  array<int, int>  $productIds
     * @return array<int, string>  productId => name
     */
    public function namesFor(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return Product::query()->whereIn('id', $productIds)->pluck('name', 'id')->all();
    }

    /**
     * Batched product id => category id, for OrderService's cancel()
     * reconstructing which category each order line's revenue/cost
     * belongs to (order_items only stores product_id, not category_id).
     * A product deleted since the sale simply has no entry — the caller
     * treats a missing id the same as "category unknown", falling back to
     * the global SALES/COGS ledgers for that line.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>  productId => categoryId
     */
    public function categoryIdsFor(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return Product::query()->whereIn('id', $productIds)->pluck('category_id', 'id')->all();
    }

    /**
     * Batched buying-price lookup — the Inventory Value report's
     * valuation basis (quantity × buying price, matching COGS's own
     * costing basis rather than selling price).
     *
     * @param  array<int, int>  $productIds
     * @return array<int, float>  productId => buyingPrice
     */
    public function buyingPricesFor(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $productIds)
            ->pluck('buying_price', 'id')
            ->map(fn ($price) => (float) $price)
            ->all();
    }

    /**
     * Order's checkout flow calls this for every line item to pull
     * authoritative, current pricing server-side — never trusts a
     * client-sent price/name. Restricted to `status: published`, same
     * public-visibility rule as the storefront listing/detail endpoints:
     * a draft or archived product shouldn't be orderable even if its ID
     * is known. Only the columns pricing/order-item snapshotting actually
     * needs are selected.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException  if the id doesn't exist or isn't published — caught globally, surfaces as a 404
     */
    public function findActiveForOrder(int $id): Product
    {
        return Product::query()
            ->select(['id', 'name', 'price', 'sale_price', 'buying_price', 'category_id'])
            ->where('status', 'published')
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginatePublic(array $filters, int $page, int $pageSize): LengthAwarePaginator
    {
        $query = Product::query()
            ->with('images', 'category')
            ->where('status', 'published');

        if (! empty($filters['category'])) {
            $query->where('category_id', (int) $filters['category']);
        }

        foreach (['featured' => 'featured', 'bestSeller' => 'best_seller', 'newArrival' => 'new_arrival'] as $param => $column) {
            if (array_key_exists($param, $filters)) {
                $query->where($column, filter_var($filters[$param], FILTER_VALIDATE_BOOLEAN));
            }
        }

        $products = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        $this->attachStockStatuses($products->items());

        return $products;
    }

    /**
     * Admin listing — unlike paginatePublic(), not restricted to
     * status=published; admins need to see drafts and archived products too.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateAdmin(array $filters, int $page, int $pageSize): LengthAwarePaginator
    {
        // AdminProductResource is shared between this list and the single
        // GET /admin/products/{id} detail — eager-load everything it reads
        // so both return the same shape (colors/sizes would otherwise be
        // silently dropped here via whenLoaded()). No 'category' here —
        // AdminProductResource only ever exposes categoryId, never a nested
        // category object (unlike the public resources), so eager-loading
        // the relation here would just be a wasted query.
        $query = Product::query()->with(['images', 'colors', 'sizes']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['category'])) {
            $query->where('category_id', (int) $filters['category']);
        }

        foreach (['featured' => 'featured', 'bestSeller' => 'best_seller', 'newArrival' => 'new_arrival'] as $param => $column) {
            if (array_key_exists($param, $filters)) {
                $query->where($column, filter_var($filters[$param], FILTER_VALIDATE_BOOLEAN));
            }
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $products = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        $this->attachStockStatuses($products->items());

        return $products;
    }

    /**
     * Dashboard's `recentProducts` list — latest N products regardless of
     * status (draft/published/archived), same unfiltered rule as
     * paginateAdmin(). Eager-loads the same relations paginateAdmin() does
     * and runs them through attachStockStatuses() so DashboardController
     * can wrap this with the exact same AdminProductResource GET
     * /admin/products uses, with no fields silently missing.
     *
     * @return Collection<int, Product>
     */
    public function recent(int $limit = 5): Collection
    {
        $products = Product::query()
            ->with(['images', 'colors', 'sizes'])
            ->latest()
            ->limit($limit)
            ->get();

        $this->attachStockStatuses($products->all());

        return $products;
    }

    public function findPublishedBySlug(string $slug): Product
    {
        // 'category' loaded without a column restriction — PublicProductResource
        // wraps it in the full CategoryResource (description/imageUrl/visible/
        // sortOrder too, not just id/name/slug), so a narrowed select() here
        // would silently leave those fields null in the response.
        $product = Product::query()
            ->with(['images', 'colors', 'sizes', 'category'])
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        $this->attachStockStatuses([$product]);

        return $product;
    }

    public function findForAdmin(int $id): Product
    {
        $product = Product::query()
            ->with(['images', 'colors', 'sizes'])
            ->findOrFail($id);

        $this->attachStockStatuses([$product]);

        return $product;
    }

    /**
     * Wrapped in a transaction: product row + child rows (images/colors/
     * sizes) + the Inventory row provisioned by the ProductCreated listener
     * + the optional quantity write are all-or-nothing. Without this, a
     * failure partway through (e.g. the 3rd color insert) would leave a
     * half-written product committed with no rollback.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $product = Product::create($this->mapAttributes($data, isCreate: true));

            $this->syncRelations($product, $data);

            // Inventory reacts to this event to provision a default row
            // (quantity 0, OUT_OF_STOCK) — Product never touches Inventory's
            // table directly, per the cross-module boundary rule. Fired
            // unconditionally and kept parameter-free on purpose: it's a
            // general "a product now exists" fact other modules (e.g.
            // Activity, later) can react to independently of this specific
            // quantity shortcut. The listener's write happens on the same
            // connection, so it's covered by this same transaction.
            ProductCreated::dispatch($product->id);

            // Optional convenience field, create-only (see StoreProductRequest).
            // This is a deliberate, one-off write orchestration — not routed
            // through the event above — going through Inventory's own update()
            // so the quantity<->status auto-derivation rule stays defined in
            // exactly one place.
            if (array_key_exists('quantity', $data) && $data['quantity'] !== null) {
                $this->inventoryService->update($product->id, ['quantity' => $data['quantity']]);
            }

            $this->attachStockStatuses([$product]);

            return $product->load(['images', 'colors', 'sizes']);
        });
    }

    /**
     * Wrapped in a transaction: the product row update and the full
     * replace of its child rows must succeed or fail together — otherwise
     * a mid-sync failure could leave old child rows deleted with the new
     * ones only partially inserted.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->fill($this->mapAttributes($data))->save();

            $this->syncRelations($product, $data);

            $this->attachStockStatuses([$product]);

            return $product->load(['images', 'colors', 'sizes']);
        });
    }

    /**
     * Wrapped in a transaction: the product row delete and the
     * ProductDeleted event's listener side effects (currently, Inventory's
     * row cleanup) must succeed or fail together, per §2.3 — a write that
     * triggers another module's write via an event listener needs the
     * same transactional guarantee as a direct multi-row write.
     */
    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $productId = $product->id;

            // FK cascadeOnDelete on product_images/product_colors/product_sizes
            // (same-module) handles the child rows. Note: this does NOT clean up
            // the corresponding Media rows/files those images point at — same
            // "no reference protection" trade-off Media's own delete already
            // has, just approached from the other direction.
            $product->delete();

            // Inventory reacts to this (DeleteInventoryRecord listener) to
            // remove the now-orphaned inventory row — product_id has no
            // FK/cascade to do this automatically, mirroring how
            // ProductCreated provisions it on the way in.
            ProductDeleted::dispatch($productId);
        });
    }

    /**
     * Images are attached here, not through create()/update() — see
     * StoreProductRequest/UpdateProductRequest, which no longer accept
     * imageUrls. Accepts multiple files in one call so the client isn't
     * forced into one request per image. Appended after any existing
     * images (sort_order continues from the current max), not a replace.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function addImages(Product $product, array $files, ?int $uploadedBy): Product
    {
        DB::transaction(function () use ($product, $files, $uploadedBy): void {
            $nextSortOrder = (int) $product->images()->max('sort_order');

            foreach (array_values($files) as $index => $file) {
                $media = $this->mediaService->store($file, 'products', $uploadedBy);

                $product->images()->create([
                    'url' => $media->url,
                    'sort_order' => $nextSortOrder + $index + 1,
                ]);
            }
        });

        $product = $product->load(['images', 'colors', 'sizes']);
        $this->attachStockStatuses([$product]);

        return $product;
    }

    /**
     * Removing a product image also deletes the Media row/file it came
     * from (via MediaService::deleteByUrl) rather than leaving it orphaned
     * — a deliberate choice for this direction, distinct from Media's own
     * DELETE /media/{id}, which has no protection against Product still
     * referencing it.
     */
    public function deleteImage(Product $product, ProductImage $image): Product
    {
        abort_unless($image->product_id === $product->id, 404, 'Resource not found.');

        DB::transaction(function () use ($image): void {
            $this->mediaService->deleteByUrl($image->url);

            $image->delete();
        });

        $product = $product->load(['images', 'colors', 'sizes']);
        $this->attachStockStatuses([$product]);

        return $product;
    }

    /**
     * Duplicate a product's content. Starts as a draft with no SKU and a
     * fresh unique slug — inventory is intentionally NOT copied (a
     * duplicate starts with no stock; Inventory owns that separately, and
     * gets its own default row via the same ProductCreated event).
     *
     * Wrapped in a transaction for the same reason as create()/update() —
     * the copy row plus every child row it copies must land together.
     */
    public function duplicate(Product $source): Product
    {
        return DB::transaction(function () use ($source) {
            $source->loadMissing(['images', 'colors', 'sizes']);

            $copy = $source->replicate(['slug', 'sku']);
            $copy->name = $source->name . ' (Copy)';
            $copy->slug = $this->uniqueSlug(Str::slug($copy->name));
            $copy->status = 'draft';
            $copy->save();

            foreach ($source->images as $image) {
                $copy->images()->create(['url' => $image->url, 'sort_order' => $image->sort_order]);
            }

            foreach ($source->colors as $color) {
                $copy->colors()->create(['name' => $color->name, 'hex' => $color->hex]);
            }

            foreach ($source->sizes as $size) {
                $copy->sizes()->create(['label' => $size->label]);
            }

            ProductCreated::dispatch($copy->id);

            $this->attachStockStatuses([$copy]);

            return $copy->load(['images', 'colors', 'sizes']);
        });
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $suffix = 1;

        while (Product::where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /**
     * Cross-module read (Product -> Inventory), batched — one call per page
     * of results, not one per product, per §15's performance guidance.
     * Mutates the given Product instances in place with a dynamic
     * `stock_status` attribute, which every Product Resource reads.
     *
     * @param  array<int, Product>  $products
     */
    private function attachStockStatuses(array $products): void
    {
        if (empty($products)) {
            return;
        }

        $productIds = array_map(fn(Product $product) => $product->id, $products);

        $statuses = $this->inventoryService->getStatusForProducts($productIds);
        $quantities = $this->inventoryService->getQuantitiesForProducts($productIds);

        foreach ($products as $product) {
            $product->stock_status = $statuses[$product->id] ?? 'OUT_OF_STOCK';
            $product->quantity = $quantities[$product->id] ?? 0;
        }
    }

    /**
     * camelCase request keys -> snake_case column names, only for keys
     * actually present (so PATCH updates stay partial).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapAttributes(array $data, bool $isCreate = false): array
    {
        $map = [
            'name' => 'name',
            'slug' => 'slug',
            'description' => 'description',
            'shortDescription' => 'short_description',
            'categoryId' => 'category_id',
            'sku' => 'sku',
            'measurementUnitId' => 'measurement_unit_id',
            'vatExempted' => 'vat_exempted',
            'buyingPrice' => 'buying_price',
            'price' => 'price',
            'salePrice' => 'sale_price',
            'material' => 'material',
            'specifications' => 'specifications',
            'status' => 'status',
            'featured' => 'featured',
            'bestSeller' => 'best_seller',
            'newArrival' => 'new_arrival',
            'seoTitle' => 'seo_title',
            'seoDescription' => 'seo_description',
        ];

        $attributes = [];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        if ($isCreate) {
            $attributes += [
                'status' => 'draft',
                'featured' => false,
                'best_seller' => false,
                'new_arrival' => false,
                'vat_exempted' => false,
            ];
        }

        return $attributes;
    }

    /**
     * Full replace on write, not a diff/merge — simplest correct behavior
     * for child collections (images/colors/sizes) and matches how the
     * contract's payload sends the complete list each time.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncRelations(Product $product, array $data): void
    {
        if (array_key_exists('colors', $data)) {
            $product->colors()->delete();

            foreach ($data['colors'] as $color) {
                $product->colors()->create(['name' => $color['name'], 'hex' => $color['hex']]);
            }
        }

        if (array_key_exists('sizes', $data)) {
            $product->sizes()->delete();

            foreach ($data['sizes'] as $label) {
                $product->sizes()->create(['label' => $label]);
            }
        }
    }
}
