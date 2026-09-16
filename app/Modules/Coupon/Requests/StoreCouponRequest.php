<?php

namespace App\Modules\Coupon\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCouponRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50', 'unique:coupons,code'],
            'type' => ['required', 'in:percentage,fixed'],
            'value' => [
                'required',
                'numeric',
                'min:0.01',
                function ($attribute, $value, $fail) {
                    if ($this->input('type') === 'percentage' && $value > 100) {
                        $fail('A percentage discount cannot exceed 100.');
                    }
                },
            ],
            'minOrderAmount' => ['nullable', 'numeric', 'min:0'],
            'maxUses' => ['nullable', 'integer', 'min:1'],
            'validFrom' => ['nullable', 'date'],
            'validTo' => ['nullable', 'date', 'after_or_equal:validFrom'],
        ];
    }
}
