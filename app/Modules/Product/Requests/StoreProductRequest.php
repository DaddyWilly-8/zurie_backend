<?php

namespace App\Modules\Product\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * No imageUrls/images field here on purpose — a product is created bare,
 * then images are attached via POST /products/{product}/images. Keeps
 * "create the record" and "upload files" as separate actions instead of
 * one request doing both. See ProductController::uploadImages().
 */
class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:products,slug'],
            'description' => ['nullable', 'string'],
            'shortDescription' => ['nullable', 'string', 'max:500'],
            'categoryId' => ['required', 'integer', 'exists:categories,id'],
            'sku' => ['nullable', 'string', 'max:100', 'unique:products,sku'],
            'buyingPrice' => ['required', 'numeric', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'salePrice' => ['nullable', 'numeric', 'min:0', 'lte:price'],
            'material' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'featured' => ['nullable', 'boolean'],
            'bestSeller' => ['nullable', 'boolean'],
            'newArrival' => ['nullable', 'boolean'],
            'colors' => ['nullable', 'array'],
            'colors.*.name' => ['required_with:colors', 'string', 'max:100'],
            'colors.*.hex' => ['required_with:colors', 'string', 'max:7'],
            'sizes' => ['nullable', 'array'],
            'sizes.*' => ['string', 'max:100'],
            'specifications' => ['nullable', 'array'],
            'specifications.*' => ['string'],
            'seoTitle' => ['nullable', 'string', 'max:255'],
            'seoDescription' => ['nullable', 'string'],

            // Optional convenience field, create-only — sets the initial
            // Inventory quantity in the same request instead of a required
            // follow-up PATCH /products/{id}/inventory call. Not accepted
            // on UpdateProductRequest; that dedicated inventory endpoint
            // stays the only way to change stock after creation.
            'quantity' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
