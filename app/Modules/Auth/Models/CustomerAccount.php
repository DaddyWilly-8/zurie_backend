<?php

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A storefront login — the customer/staff split's other half of `User`
 * (staff-only from this point on). Authenticated against the dedicated
 * 'customer' Auth guard (config/auth.php), never 'web' — the two guards
 * share the same underlying session cookie (Sanctum's statefulApi()
 * middleware doesn't care which guard checks it) but are independent
 * booleans within it, which is what fixes both original bugs this split
 * was built for: an admin session no longer appears as a logged-in
 * customer on the storefront, and a customer session can never reach an
 * admin (`permission:xxx`) route, because EnsurePermission and every
 * admin controller resolve `$request->user()` (implicitly the 'web'
 * guard) while every customer-only controller explicitly resolves
 * `$request->user('customer')`.
 *
 * No roles/permissions relationship — customers never go through the RBAC
 * system at all, by design.
 */
#[Fillable(['name', 'email', 'password', 'stakeholder_id'])]
#[Hidden(['password', 'remember_token'])]
class CustomerAccount extends Authenticatable
{
    use Notifiable;

    protected $table = 'customer_accounts';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
