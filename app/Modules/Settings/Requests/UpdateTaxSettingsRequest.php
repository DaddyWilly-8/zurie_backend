<?php

namespace App\Modules\Settings\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaxSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'vatPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'pricesIncludeVat' => ['required', 'boolean'],
        ];
    }
}
