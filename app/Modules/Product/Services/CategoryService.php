<?php

namespace App\Modules\Product\Services;

use App\Modules\Media\Services\MediaService;
use App\Modules\Product\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CategoryService
{
    public function __construct(private readonly MediaService $mediaService) {}

    /**
     * GET /categories is public/unauthenticated — only ever surfaces
     * visible=true categories, same reasoning as Product's status filter on
     * its public listing. See adminList() for the unfiltered admin
     * counterpart, mirroring Product's GET /products vs GET /admin/products
     * split.
     */
    public function list(): Collection
    {
        return Category::query()
            ->where('visible', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * GET /admin/categories — deliberately a separate method from list(),
     * not the same query with an admin flag, for the same reason Product's
     * paginateAdmin() is separate from paginatePublic(): the public path's
     * visible=true filter must never be at risk of a shared-code-path bug
     * leaking a hidden category to the storefront.
     */
    public function adminList(): Collection
    {
        return Category::query()
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Dashboard's `totalCategories` stat — every category regardless of
     * `visible`, same "total means total" treatment as
     * ProductService::countTotal().
     */
    public function countTotal(): int
    {
        return Category::query()->count();
    }

    public function find(int $id): Category
    {
        return Category::query()->findOrFail($id);
    }

    /**
     * Nullable counterpart to find() — for callers (OrderService's
     * per-category ledger resolution) that need to treat "category
     * deleted since the sale" as a normal fallback case, not an error.
     */
    public function findOrNull(int $id): ?Category
    {
        return Category::query()->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Category
    {
        return Category::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'visible' => $data['visible'] ?? true,
            'sort_order' => $data['sortOrder'] ?? 0,
            'income_ledger_id' => $data['incomeLedgerId'] ?? null,
            'expense_ledger_id' => $data['expenseLedgerId'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Category $category, array $data): Category
    {
        // incomeLedgerId/expenseLedgerId use array_key_exists, not `??` —
        // the client must be able to explicitly clear one back to null
        // (falling back to the global system ledger again), and `??`
        // would silently keep the old value instead since `null ?? $old`
        // evaluates to $old, not null.
        $category->fill([
            'name' => $data['name'] ?? $category->name,
            'slug' => $data['slug'] ?? $category->slug,
            'description' => $data['description'] ?? $category->description,
            'visible' => $data['visible'] ?? $category->visible,
            'sort_order' => $data['sortOrder'] ?? $category->sort_order,
            'income_ledger_id' => array_key_exists('incomeLedgerId', $data) ? $data['incomeLedgerId'] : $category->income_ledger_id,
            'expense_ledger_id' => array_key_exists('expenseLedgerId', $data) ? $data['expenseLedgerId'] : $category->expense_ledger_id,
        ])->save();

        return $category;
    }

    public function delete(Category $category): void
    {
        // Same trade-off as Product::delete() — does not clean up the
        // Media row/file behind image_url. Only the dedicated image
        // endpoints (setImage/deleteImage below) cascade into Media.
        $category->delete();
    }

    /**
     * Category holds a single image_url, not a child table like Product's
     * images, so setting an image always replaces whatever's already
     * there — the old Media row/file is cleaned up first via
     * MediaService::deleteByUrl(), same as Product's deleteImage().
     */
    public function setImage(Category $category, UploadedFile $file, ?int $uploadedBy): Category
    {
        DB::transaction(function () use ($category, $file, $uploadedBy): void {
            if ($category->image_url) {
                $this->mediaService->deleteByUrl($category->image_url);
            }

            $media = $this->mediaService->store($file, 'categories', $uploadedBy);

            $category->update(['image_url' => $media->url]);
        });

        return $category->fresh();
    }

    public function deleteImage(Category $category): Category
    {
        if ($category->image_url) {
            DB::transaction(function () use ($category): void {
                $this->mediaService->deleteByUrl($category->image_url);

                $category->update(['image_url' => null]);
            });
        }

        return $category->fresh();
    }
}
