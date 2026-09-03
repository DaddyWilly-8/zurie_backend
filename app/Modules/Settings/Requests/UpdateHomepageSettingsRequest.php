<?php

namespace App\Modules\Settings\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * No heroImageUrl here — written exclusively through
 * POST /admin/settings/homepage/hero-image (UploadHomepageHeroImageRequest).
 */
class UpdateHomepageSettingsRequest extends FormRequest
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
            'heroHeading' => ['required', 'string', 'max:255'],
            'heroSubheading' => ['nullable', 'string', 'max:500'],
            'heroCtaText' => ['nullable', 'string', 'max:100'],
            'heroCtaUrl' => ['nullable', 'url', 'max:500'],
        ];
    }
}
