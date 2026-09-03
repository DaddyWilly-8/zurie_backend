<?php

namespace App\Modules\Product\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Category;
use App\Modules\Product\Requests\StoreCategoryImageRequest;
use App\Modules\Product\Requests\StoreCategoryRequest;
use App\Modules\Product\Requests\UpdateCategoryRequest;
use App\Modules\Product\Resources\CategoryResource;
use App\Modules\Product\Services\CategoryService;
use App\Support\Http\ApiResponse;

class CategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CategoryService $categoryService) {}

    public function index()
    {
        return $this->ok(CategoryResource::collection($this->categoryService->list()));
    }

    /**
     * GET /admin/categories — unlike index(), not restricted to
     * visible=true; admins need to see and manage hidden categories too.
     */
    public function adminIndex()
    {
        return $this->ok(CategoryResource::collection($this->categoryService->adminList()));
    }

    public function show(Category $category)
    {
        return $this->ok(new CategoryResource($category));
    }

    public function store(StoreCategoryRequest $request)
    {
        $category = $this->categoryService->create($request->validated());

        return $this->created(new CategoryResource($category));
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $category = $this->categoryService->update($category, $request->validated());

        return $this->ok(new CategoryResource($category));
    }

    public function destroy(Category $category)
    {
        $this->categoryService->delete($category);

        return $this->ok();
    }

    /**
     * POST /categories/{category}/image — a category only ever has one
     * image, so this replaces whatever's currently set rather than
     * appending. create()/update() no longer accept imageUrl.
     */
    public function uploadImage(StoreCategoryImageRequest $request, Category $category)
    {
        $category = $this->categoryService->setImage($category, $request->file('image'), $request->user()?->id);

        return $this->ok(new CategoryResource($category));
    }

    public function deleteImage(Category $category)
    {
        $category = $this->categoryService->deleteImage($category);

        return $this->ok(new CategoryResource($category));
    }
}
