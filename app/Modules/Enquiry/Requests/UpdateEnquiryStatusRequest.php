<?php

namespace App\Modules\Enquiry\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEnquiryStatusRequest extends FormRequest
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
            'status' => ['required', 'in:new,read,responded,archived'],
        ];
    }
}
