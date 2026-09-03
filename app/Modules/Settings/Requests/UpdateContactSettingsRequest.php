<?php

namespace App\Modules\Settings\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `present`, not `required`, on the 3 array fields — same lesson learned
 * from UpdateRolePermissionsRequest's permissionIds bug: `required` treats
 * an empty array as absent, which would make it impossible to send
 * `"phones": []` to clear every phone number while leaving emails/
 * socialLinks untouched. `present` only demands the key exist.
 *
 * `label` is free text on purpose, not an enum — the admin can label a
 * phone number anything ("Sales", "WhatsApp Orders", "Nairobi Branch"),
 * per your requirement that phone types not be a fixed list.
 */
class UpdateContactSettingsRequest extends FormRequest
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
            'phones' => ['present', 'array'],
            'phones.*.label' => ['required', 'string', 'max:100'],
            'phones.*.value' => ['required', 'string', 'max:50'],

            'emails' => ['present', 'array'],
            'emails.*.label' => ['required', 'string', 'max:100'],
            'emails.*.value' => ['required', 'email', 'max:255'],

            'socialLinks' => ['present', 'array'],
            'socialLinks.*.platform' => ['required', 'string', 'max:100'],
            'socialLinks.*.url' => ['required', 'url', 'max:500'],
        ];
    }
}
