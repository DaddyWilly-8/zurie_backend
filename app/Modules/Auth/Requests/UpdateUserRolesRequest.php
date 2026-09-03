<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRolesRequest extends FormRequest
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
            'roleIds' => ['required', 'array', 'min:1'],
            'roleIds.*' => ['integer', 'exists:roles,id'],
        ];
    }
}
