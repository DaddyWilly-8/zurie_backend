<?php

namespace App\Modules\Settings\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadBrandLogoRequest extends FormRequest
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
            // 'svg' deliberately excluded, unlike every other image field in the
            // app briefly allowed it here — SVG is XML and can carry an embedded
            // <script>, and MediaController::serve() returns it with no
            // sanitization or Content-Disposition: attachment. See
            // zurie-backend-security-audit.md — stored-XSS risk via the logo.
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
