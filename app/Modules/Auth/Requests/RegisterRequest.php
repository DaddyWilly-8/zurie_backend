<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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
