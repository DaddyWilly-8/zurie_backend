<?php

namespace App\Modules\Coupon\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewCouponRequest extends FormRequest
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
            'code' => ['required', 'string'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ];
    }
}
