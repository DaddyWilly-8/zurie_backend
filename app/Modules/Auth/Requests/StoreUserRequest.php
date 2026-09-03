<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
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
            'email' => ['required', 'email', 'unique:users,email'],
            // Same policy as ResetPasswordRequest — see that class's
            // comment for why ->uncompromised() is deliberately omitted.
            // No 'confirmed' here — this is an admin creating another
            // user's account, not the user setting their own password, and
            // adding a required password_confirmation field would be an
            // API contract change no one asked for.
            'password' => ['required', Password::min(8)->letters()->mixedCase()->numbers()],
        ];
    }
}
