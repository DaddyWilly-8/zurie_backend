<?php

namespace App\Modules\Settings\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePolicySettingsRequest extends FormRequest
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
            'deliveryPolicy' => ['nullable', 'string'],
            'returnPolicy' => ['nullable', 'string'],
        ];
    }
}
