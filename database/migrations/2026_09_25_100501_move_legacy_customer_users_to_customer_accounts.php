<?php

use App\Modules\Auth\Models\CustomerAccount;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Finishes the customer/staff split for data created before it.
     * Pre-split, every storefront signup (password or Google) was a `users`
     * row holding the 'customer' role; the split moved new signups to
     * `customer_accounts` but never moved those existing rows, so they kept
     * showing up in Admin Users with a "Customer" badge.
     *
     * For each user whose ONLY role is 'customer':
     *  - reuse the customer_accounts row with the same email if one exists
     *    (they may already have signed in again post-split, e.g. via Google),
     *    otherwise create one carrying over name, password hash and
     *    email_verified_at, so their existing password keeps working;
     *  - carry over the stakeholder link (stakeholders.user_id → the
     *    account's stakeholder_id), so order history/wishlist/reviews stay
     *    attached;
     *  - re-point their notifications to the customer account;
     *  - delete the `users` row (user_roles and oauth_identities cascade;
     *    other FKs to users are nullOnDelete).
     * A user who also holds a staff role keeps their `users` row and only
     * loses the inert 'customer' role. A user still referenced by a
     * restrictOnDelete FK (cashier_sessions.opened_by, currencies.created_by)
     * is left in place and logged rather than failing the whole migration.
     * Finally the unused 'customer' role row itself is removed.
     *
     * Idempotent: running it again finds no customer-only users.
     */
    public function up(): void
    {
        $customerRoleId = DB::table('roles')->where('name', 'customer')->value('id');

        if ($customerRoleId === null) {
            return;
        }

        $userIds = DB::table('user_roles')->where('role_id', $customerRoleId)->pluck('user_id');

        foreach ($userIds as $userId) {
            DB::transaction(fn () => $this->moveUser((int) $userId, (int) $customerRoleId));
        }

        if (! DB::table('user_roles')->where('role_id', $customerRoleId)->exists()) {
            DB::table('roles')->where('id', $customerRoleId)->delete();
        }
    }

    private function moveUser(int $userId, int $customerRoleId): void
    {
        $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();

        if ($user === null) {
            return;
        }

        $hasStaffRole = DB::table('user_roles')
            ->where('user_id', $userId)
            ->where('role_id', '!=', $customerRoleId)
            ->exists();

        if ($hasStaffRole) {
            DB::table('user_roles')->where('user_id', $userId)->where('role_id', $customerRoleId)->delete();

            return;
        }

        $blockedBy = collect([
            'cashier_sessions' => 'opened_by',
            'currencies' => 'created_by',
        ])->filter(fn ($column, $table) => DB::table($table)->where($column, $userId)->exists());

        if ($blockedBy->isNotEmpty()) {
            Log::warning('Legacy customer user not moved: still referenced by a staff record', [
                'user_id' => $userId,
                'referenced_by' => $blockedBy->keys()->all(),
            ]);

            return;
        }

        $stakeholderId = DB::table('stakeholders')->where('user_id', $userId)->value('id');

        // customer_accounts.stakeholder_id is unique — never steal a
        // stakeholder another account already owns.
        if ($stakeholderId !== null
            && DB::table('customer_accounts')->where('stakeholder_id', $stakeholderId)->exists()) {
            $stakeholderId = null;
        }

        $account = DB::table('customer_accounts')->where('email', $user->email)->first();

        if ($account === null) {
            $accountId = DB::table('customer_accounts')->insertGetId([
                'name' => $user->name,
                'email' => $user->email,
                'password' => $user->password,
                'email_verified_at' => $user->email_verified_at,
                'stakeholder_id' => $stakeholderId,
                'created_at' => $user->created_at,
                'updated_at' => now(),
            ]);
        } else {
            $accountId = $account->id;

            if ($account->stakeholder_id === null && $stakeholderId !== null) {
                DB::table('customer_accounts')->where('id', $accountId)->update([
                    'stakeholder_id' => $stakeholderId,
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->update(['notifiable_type' => CustomerAccount::class, 'notifiable_id' => $accountId]);

        DB::table('sessions')->where('user_id', $userId)->delete();
        DB::table('users')->where('id', $userId)->delete();
    }

    /**
     * Not reversible: the moved rows are ordinary customer accounts now and
     * may have changed since. Restore from a backup if this must be undone.
     */
    public function down(): void {}
};
