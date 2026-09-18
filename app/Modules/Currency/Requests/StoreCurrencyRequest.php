<?php

namespace App\Modules\Currency\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCurrencyRequest extends FormRequest
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
            'namePlural' => ['required', 'string', 'max:255'],
            // Format-validated only (3-5 uppercase letters, e.g. "TZS",
            // "USD") — not checked against a real ISO-4217 lookup table
            // (the reference doc's rule), since this app has no such
            // lookup service available. A deliberate, smaller-scope
            // stand-in for that rule.
            'code' => ['required', 'string', 'min:3', 'max:5', 'regex:/^[A-Za-z]+$/', 'unique:currencies,code'],
            'symbol' => ['required', 'string', 'max:5'],
            'symbolNative' => ['required', 'string', 'max:5'],
            'decimalDigits' => ['nullable', 'integer', 'min:0', 'max:6'],
            'isBase' => ['nullable', 'boolean'],
        ];
    }
}
