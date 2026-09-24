<?php

namespace App\Modules\Product\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductImage;
use App\Modules\Product\Requests\StoreProductImagesRequest;
use App\Modules\Product\Requests\StoreProductRequest;
use App\Modules\Product\Requests\UpdateProductRequest;
use App\Modules\Product\Resources\AdminProductResource;
use App\Modules\Product\Resources\PublicProductListResource;
use App\Modules\Product\Resources\PublicProductResource;
use App\Modules\Product\Services\ProductService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductService $productService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = $this->pageSize($request);

        $filters = $request->only(['category', 'featured', 'bestSeller', 'newArrival']);

        $products = $this->productService->paginatePublic($filters, $page, $pageSize);

        return $this->paginated(
            PublicProductListResource::collection($products->items()),
            [
                'count' => $products->total(),
                'page' => $products->currentPage(),
                'pageSize' => $products->perPage(),
            ]
        );
    }

    public function show(string $slug)
    {
        $product = $this->productService->findPublishedBySlug($slug);

        return $this->ok(new PublicProductResource($product));
    }

    /**
     * GET /admin/products — unlike index(), not restricted to
     * status=published, and returns AdminProductResource (includes buyingPrice).
     */
    public function adminIndex(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = $this->pageSize($request);

        $filters = $request->only(['status', 'category', 'featured', 'bestSeller', 'newArrival', 'search']);

        $products = $this->productService->paginateAdmin($filters, $page, $pageSize);

        return $this->paginated(
            AdminProductResource::collection($products->items()),
            [
                'count' => $products->total(),
                'page' => $products->currentPage(),
                'pageSize' => $products->perPage(),
            ]
        );
    }

    public function adminShow(int $id)
    {
        $product = $this->productService->findForAdmin($id);

        return $this->ok(new AdminProductResource($product));
    }

    public function store(StoreProductRequest $request)
    {
        $product = $this->productService->create($request->validated());

        return $this->created(new AdminProductResource($product));
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $product = $this->productService->update($product, $request->validated());

        return $this->ok(new AdminProductResource($product));
    }

    public function destroy(Product $product)
    {
        $this->productService->delete($product);

        return $this->ok();
    }

    public function duplicate(Product $product)
    {
        $copy = $this->productService->duplicate($product);

        return $this->created(new AdminProductResource($copy));
    }

    /**
     * POST /products/{product}/images — the only way images get attached
     * to a product now; create()/update() no longer accept imageUrls.
     * Accepts multiple files in one request.
     */
    public function uploadImages(StoreProductImagesRequest $request, Product $product)
    {
        $product = $this->productService->addImages(
            $product,
            $request->file('images'),
            $request->user()?->id,
        );

        return $this->created(new AdminProductResource($product));
    }

    public function deleteImage(Product $product, ProductImage $image)
    {
        $product = $this->productService->deleteImage($product, $image);

        return $this->ok(new AdminProductResource($product));
    }
}
