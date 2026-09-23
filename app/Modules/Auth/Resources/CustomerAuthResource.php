<?php

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape for the customer guard's login/register/user endpoints —
 * deliberately mirrors AuthUserResource's `{user, roles, permissions}`
 * envelope (roles/permissions always empty; customers never go through
 * RBAC) so the frontend's existing session-parsing code works unchanged
 * for either guard.
 */
class CustomerAuthResource extends JsonResource
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
                'hasProfile' => $this->stakeholder_id !== null,
            ],
            'roles' => [],
            'permissions' => [],
        ];
    }
}
