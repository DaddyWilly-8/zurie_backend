<?php

namespace App\Modules\Target\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTargetRequest extends FormRequest
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
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'targetAmount' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
