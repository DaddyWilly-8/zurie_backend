<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `present`, not `required` — Laravel's `required` rule treats an empty
     * array as "not present" and fails it, which would make it impossible
     * to ever send `{ permissionIds: [] }` to revoke every permission from
     * a role. `present` only demands the key exists in the payload; an
     * empty array satisfies it. No min:1 either, unlike
     * UpdateUserRolesRequest's roleIds — a role with zero permissions is a
     * legitimate state, and revoking everything is exactly the case this
     * full-sync endpoint exists to support that POST /roles/{role}/permissions
     * (additive-only) can't.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissionIds' => ['present', 'array'],
            'permissionIds.*' => ['integer', 'exists:permissions,id'],
        ];
    }
}
