<?php

namespace App\Modules\Enquiry\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEnquiryRequest extends FormRequest
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
            // One way to reply is enough: WhatsApp/phone enquiries often
            // come without an email.
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255'],
            'message' => ['required', 'string'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:50'],
            'subject' => ['nullable', 'string', 'max:255'],
        ];
    }
}
