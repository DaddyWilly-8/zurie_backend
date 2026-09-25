<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\CustomerAccount;
use App\Modules\Customer\Services\CustomerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Owns CustomerAccount as a login/identity record — creating one,
 * linking it to a Customer/stakeholder, and looking one up by its link.
 * Deliberately separate from CustomerAuthService (which owns the
 * session/guard mechanics: attempt(), logout(), the 2FA-style challenge
 * flow if one is ever added here) — same split FinanceService/
 * LedgerService already established between "posting" and "admin CRUD".
 */
class CustomerAccountService
{
    public function __construct(private readonly CustomerService $customerService) {}

    /**
     * Public storefront signup — creates a CustomerAccount and links/
     * merges a Customer record (see CustomerService::linkAccount()'s own
     * docblock for the guest-history-merge behavior), same "always
     * available, never mandatory to complete a purchase" rule as before
     * the split.
     *
     * @param  array<string, mixed>  $data  name, email, password, phone, whatsappNumber?
     */
    public function register(array $data): CustomerAccount
    {
        return DB::transaction(function () use ($data) {
            $account = CustomerAccount::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            // findOrCreate(), not linkAccount() — linkAccount() writes
            // its $userId argument into `stakeholders.user_id`, which
            // still carries a real FK to the now-staff-only `users`
            // table (see the customer_accounts migration's docblock for
            // why that column was deliberately left untouched). A
            // CustomerAccount's id is never a valid users.id, so passing
            // it there would violate that FK — caught by a real 500 in
            // testing. findOrCreate() with no $userId dedupes purely by
            // phone (the same guest-history-merge behavior linkAccount()
            // provided) without touching that column at all; the forward
            // link is customer_accounts.stakeholder_id, set below.
            $customer = $this->customerService->findOrCreate([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'whatsapp_number' => $data['whatsappNumber'] ?? null,
                'email' => $data['email'],
            ]);

            $account->update(['stakeholder_id' => $customer->id]);

            return $account;
        });
    }

    /**
     * Google (or any future Socialite provider) sign-in/sign-up — moved
     * here from the pre-split AuthService, since Google login only ever
     * created storefront customers, never staff. Simplified from the
     * pre-split version, which also tracked an `oauth_identities` row
     * keyed by provider+providerId before falling back to an email match
     * — that extra indirection only mattered for resolving a case where
     * the same person's Google account and password account had
     * *different* emails, which Google's own verified-email guarantee
     * makes vanishingly unlikely in practice. Matching on email alone is
     * simpler and still correct for the case that actually occurs: "sign
     * up with a password" and "sign in with Google" using the same
     * address converge on one account. `oauth_identities` (and
     * OAuthIdentity's `user_id` FK to the now-staff-only `users` table)
     * is left in place but unused by this path — revisit if a second
     * Socialite provider or the identity-tracking behavior is ever
     * needed again.
     *
     * Deliberately does NOT create a Customer/stakeholder record —
     * Google never supplies a phone number, and CustomerService::
     * linkAccount() requires one; a customer who signs up via Google
     * completes their profile on first order/account visit, same as
     * before the split.
     */
    public function findOrCreateForSocialite(string $email, ?string $name): CustomerAccount
    {
        $account = CustomerAccount::where('email', $email)->first();

        if ($account !== null) {
            return $account;
        }

        return CustomerAccount::create([
            'email' => $email,
            'name' => $name,
            'password' => Hash::make(Str::random(40)),
        ]);
    }

    public function findByStakeholderId(int $stakeholderId): ?CustomerAccount
    {
        return CustomerAccount::where('stakeholder_id', $stakeholderId)->first();
    }

    /**
     * Completes the "profile finishes on first order" promise
     * findOrCreateForSocialite()'s docblock made but nothing ever
     * actually implemented — a Google sign-up has no phone, so it never
     * gets a stakeholder_id at registration; every checkout from that
     * account went through OrderService::checkout()'s guest/phone-match
     * path (since accountCustomerId was null) and created or matched a
     * Customer, but nothing ever linked it back to the account. Left
     * permanently unlinked, that account could place orders forever but
     * never see its own order history, submit a review ("No customer
     * profile is linked to this account"), or receive a notification —
     * every one of those reads stakeholder_id straight off the account.
     * Called right after checkout with the Order's own customer_id;
     * a no-op if the account is already linked (the normal-registration
     * case, checkout's existingCustomerId path, never needs this).
     */
    public function linkStakeholderIfMissing(CustomerAccount $account, int $stakeholderId): void
    {
        if ($account->stakeholder_id === null) {
            $account->update(['stakeholder_id' => $stakeholderId]);
        }
    }

    /**
     * Called after a password reset (CustomerAuthController::resetPassword()),
     * mirroring AuthService::invalidateSessionsFor()'s "kill every session
     * predating a reset" intent for the customer guard. Currently a
     * documented no-op: the `sessions` table has no column identifying
     * which guard a given row's session belongs to (only Laravel's own
     * internal login_* keys inside the serialized payload distinguish
     * 'web' from 'customer'), so AuthService's blunt delete-by-user_id
     * approach doesn't translate here without a real per-guard-aware
     * session store (Redis with tagged keys, or a payload sweep) — out of
     * scope for this pass. The password itself still changes, which is
     * the part that matters most; a stale pre-reset session simply stays
     * valid until it idles out on its own.
     */
    public function invalidateSessionsFor(CustomerAccount $account): void
    {
        // Intentionally empty — see docblock above.
    }
}
