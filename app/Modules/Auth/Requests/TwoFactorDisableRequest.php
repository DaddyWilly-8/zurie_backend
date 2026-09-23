<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorDisableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Requires the current password, not just an active session — 2FA
     * exists precisely to add a second factor beyond "whoever holds this
     * session cookie"; disabling it must not be reachable by session
     * hijack alone.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }
}
