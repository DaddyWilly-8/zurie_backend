<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            // Aligned with StoreUserRequest's policy (admin-created
            // accounts) during a security review — self-registration
            // previously only required a plain 8-char minimum with no
            // complexity requirement, an inconsistent, weaker policy for
            // the exact same password field on a different creation path.
            'password' => ['required', Password::min(8)->letters()->mixedCase()->numbers(), 'confirmed'],
            // A phone already claimed by another registered account (i.e.
            // a stakeholders row with a real user_id) can't be reused — bug
            // found in testing: without this check, the request reached
            // CustomerService::linkAccount(), hit the phone's DB unique
            // constraint, and surfaced as a generic 500/409 instead of a
            // clear validation error. A guest/walk-in row with this same
            // phone and no user_id is still fine to claim (that's the
            // intended merge behavior), so this only rejects the
            // already-registered case. Checked against `stakeholders`
            // (Phase C's Stakeholder merge — Customer now shares that
            // table) rather than the now-dropped `customers` table.
            'phone' => [
                'required',
                'string',
                'max:255',
                Rule::unique('stakeholders', 'phone')->whereNotNull('user_id'),
            ],
            'whatsappNumber' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.unique' => 'This phone number is already registered to an account.',
        ];
    }
}
