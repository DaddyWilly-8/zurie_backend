<?php

namespace App\Modules\Coupon\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCouponActiveRequest extends FormRequest
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
            'isActive' => ['required', 'boolean'],
        ];
    }
}
