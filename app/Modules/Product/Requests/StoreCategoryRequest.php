<?php

namespace App\Modules\Product\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * No imageUrl field here on purpose — a category is created bare, then its
 * image is set via POST /categories/{category}/image. See
 * CategoryController::uploadImage().
 */
class StoreCategoryRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255', 'unique:categories,slug'],
            'description' => ['nullable', 'string'],
            'visible' => ['nullable', 'boolean'],
            'sortOrder' => ['nullable', 'integer', 'min:0'],
            // Independent, both optional — see the migration that added
            // these columns for the fallback-to-global-ledger reasoning.
            'incomeLedgerId' => ['nullable', 'integer', 'exists:ledgers,id'],
            'expenseLedgerId' => ['nullable', 'integer', 'exists:ledgers,id'],
        ];
    }
}
