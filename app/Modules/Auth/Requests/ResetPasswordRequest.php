<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            // min:8 alone (the previous rule) accepted "aaaaaaaa". Letters
            // + mixed case + numbers is a meaningful floor without being
            // obnoxious. Deliberately NOT ->uncompromised() — that check
            // calls the Have I Been Pwned API over HTTPS on every password
            // set, and an outbound-network hiccup on shared hosting would
            // then block every password reset, not just weak ones. Worth
            // revisiting once production network reliability is confirmed.
            // See zurie-backend-security-audit.md, item #6.
            'password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()],
        ];
    }
}
