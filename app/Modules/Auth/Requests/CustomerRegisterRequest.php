<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CustomerRegisterRequest extends FormRequest
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
            'email' => ['required', 'email', 'unique:customer_accounts,email'],
            'password' => ['required', Password::min(8)->letters()->mixedCase()->numbers(), 'confirmed'],
            // See the pre-split RegisterRequest's own docblock for why
            // this only rejects a phone already claimed by a *linked*
            // CustomerAccount, not a guest/walk-in row with no account.
            'phone' => [
                'required',
                'string',
                'max:255',
                Rule::unique('stakeholders', 'phone')->whereIn('id', function ($query) {
                    $query->select('stakeholder_id')->from('customer_accounts')->whereNotNull('stakeholder_id');
                }),
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
