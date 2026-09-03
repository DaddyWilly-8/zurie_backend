<?php

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape for POST /auth/login and GET /auth/user — see zurie-api-contract.md §2.
 * `permissions` is a flat, de-duplicated UI convenience only, never the
 * authorization boundary itself.
 */
class AuthUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id' => $this->id,
                'name' => $this->name,
                'email' => $this->email,
            ],
            'roles' => $this->roles->pluck('name')->values(),
            'permissions' => $this->permissionKeys(),
        ];
    }
}
