<?php

namespace App\Modules\Product\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * No imageUrls/images field here either — images are managed exclusively
 * through POST/DELETE /products/{product}/images, not through this
 * general-purpose update. See StoreProductRequest for the same call on create.
 */
class UpdateProductRequest extends FormRequest
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
        $product = $this->route('product');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product)],
            'description' => ['nullable', 'string'],
            'shortDescription' => ['nullable', 'string', 'max:500'],
            'categoryId' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product)],
            'measurementUnitId' => ['nullable', 'integer', 'exists:measurement_units,id'],
            'vatExempted' => ['nullable', 'boolean'],
            'buyingPrice' => ['sometimes', 'required', 'numeric', 'min:0'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'salePrice' => ['nullable', 'numeric', 'min:0', function ($attribute, $value, $fail) use ($product): void {
                if ($value === null) {
                    return;
                }

                $price = $this->input('price', $product?->price);

                if ($price !== null && (float) $value > (float) $price) {
                    $fail('The sale price must not be greater than the price.');
                }
            }],
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
        ];
    }
}
