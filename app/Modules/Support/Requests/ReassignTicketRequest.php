<?php

namespace App\Modules\Support\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReassignTicketRequest extends FormRequest
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
            // Existence + "must actually be staff" is checked in the
            // service/controller against the resolved User, not here —
            // this request only knows it needs to be a real users.id.
            'toUserId' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
