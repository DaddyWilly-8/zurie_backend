<?php

namespace App\Modules\Review\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReviewStatusRequest extends FormRequest
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
            'status' => ['required', 'in:pending,approved,rejected'],
        ];
    }
}
