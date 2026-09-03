<?php

namespace App\Modules\Settings\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * No logoUrl here — that field is written exclusively through
 * POST /admin/settings/brand/logo (UploadBrandLogoRequest), never accepted
 * as a plain string in this payload.
 */
class UpdateBrandSettingsRequest extends FormRequest
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
            'siteName' => ['required', 'string', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:255'],
        ];
    }
}
