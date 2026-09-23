<?php

namespace App\Modules\Customer\Services;

use App\Modules\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Phase C (Stakeholder merge) — Customer now shares its physical table
 * (`stakeholders`) with Supplier. Every method below is scoped to
 * `is_customer_role = true` for *listing/lookup-by-id* purposes (so a
 * supplier-only row never shows up as a customer to an admin), but the
 * dedup lookups in findOrCreate()/linkAccount() deliberately search
 * *all* stakeholders regardless of role — if a real person is both a
 * walk-in customer and a supplier sharing the same phone number, that's
 * one real-world entity and should resolve to one row, not two. Whenever
 * a match is found (new or existing), `is_customer_role` is explicitly
 * set true on it — including when the match started life as a
 * supplier-only row — since firstOrCreate()'s second argument only
 * applies on actual creation, not on an existing match.
 */
class CustomerService
{
    /**
     * Dedupe by phone, DB-unique-indexed, for guests/walk-ins with no
     * user_id. Called by Order's checkout flow — there is intentionally no
     * generic POST /customers; a customer record is only ever created as a
     * side effect of order placement or account registration, per
     * zurie-backend-implementation-spec.md §6.
     *
     * When `userId` is present (a logged-in customer checking out), looks
     * up by user_id first so their order lands on their existing profile
     * even if they check out with a different phone number than last time
     * — falls back to creating a new linked record on first purchase.
     * Guest checkout (`userId` null) is unchanged: dedupe by phone only.
     *
     * @param  array<string, mixed>  $data  name, phone, whatsapp_number?, email?
     */
    public function findOrCreate(array $data, ?int $userId = null): Customer
    {
        // is_active/is_customer_role set explicitly rather than left to
        // the DB column default — Eloquent's create() doesn't reload
        // DB-applied defaults into the in-memory model (same bug class
        // fixed repeatedly elsewhere in this codebase).
        $defaults = [
            'name' => $data['name'],
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'email' => $data['email'] ?? null,
            'is_active' => true,
            'is_customer_role' => true,
        ];

        $customer = $userId !== null
            ? Customer::firstOrCreate(['user_id' => $userId], $defaults + ['phone' => $data['phone']])
            : Customer::firstOrCreate(['phone' => $data['phone']], $defaults);

        if (! $customer->is_customer_role) {
            $customer->update(['is_customer_role' => true]);
        }

        return $customer;
    }

    public function paginateAdmin(int $page, int $pageSize): LengthAwarePaginator
    {
        return $this->withAccountFlag(Customer::query())
            ->where('is_customer_role', true)
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findForAdmin(int $id): Customer
    {
        return $this->withAccountFlag(Customer::query())->where('is_customer_role', true)->findOrFail($id);
    }

    /**
     * Attaches `has_customer_account` (CustomerResource's `isRegistered`)
     * via a raw subquery against `customer_accounts` by table name, not
     * the Eloquent model — the same documented read-only cross-module
     * pattern GrnService/DeliveryService already use, rather than a hard
     * class dependency on Auth's CustomerAccount. `stakeholders.user_id`
     * (the pre-customer/staff-split link) is deliberately NOT used for
     * this anymore — see CustomerService::findByUserId()'s own
     * @deprecated note for why it no longer reflects new signups.
     */
    private function withAccountFlag(Builder $query): Builder
    {
        // Raw EXISTS(...), not the addSelect(['alias' => Closure]) sugar
        // (that syntax wraps a subquery Builder/Closure as a correlated
        // SELECT, which can return SQL NULL on no match) — EXISTS()
        // guarantees a plain 1 or 0 always, so CustomerResource's
        // `?? fallback` can reliably tell "flag attached and false" apart
        // from "flag never attached at all" (NULL would be ambiguous
        // with the latter).
        return $query->select('*')->addSelect(DB::raw(
            'EXISTS (SELECT 1 FROM customer_accounts WHERE customer_accounts.stakeholder_id = stakeholders.id) as has_customer_account'
        ));
    }

    /**
     * Called once, at account registration — links a new user to a
     * Customer record. If a guest/walk-in record already exists with the
     * same phone number, that record is claimed (user_id attached) rather
     * than creating a duplicate, so any pre-existing guest order history
     * becomes part of the new account's history — the "Mary" scenario from
     * Zurie_V2_Architecture_Design (2)'s Customer Architecture section:
     * one customer, multiple channels, one history. Idempotent: a user_id
     * already linked to a Customer just returns that same record.
     *
     * @param  array<string, mixed>  $data  name, phone, whatsapp_number?, email?
     */
    public function linkAccount(int $userId, array $data): Customer
    {
        $existing = Customer::where('user_id', $userId)->first();
        if ($existing !== null) {
            if (! $existing->is_customer_role) {
                $existing->update(['is_customer_role' => true]);
            }

            return $existing;
        }

        // Deliberately not scoped to is_customer_role here — matches any
        // stakeholder by phone with no login yet, including one that
        // started life as a supplier-only row (same real-world-identity
        // reasoning as findOrCreate() above).
        $guestMatch = Customer::where('phone', $data['phone'])->whereNull('user_id')->first();
        if ($guestMatch !== null) {
            $guestMatch->update(['user_id' => $userId, 'name' => $data['name'], 'is_customer_role' => true]);

            return $guestMatch;
        }

        return Customer::create([
            'user_id' => $userId,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'email' => $data['email'] ?? null,
            'is_active' => true,
            'is_customer_role' => true,
        ]);
    }

    /**
     * @deprecated pre-customer/staff-split lookup — `stakeholders.user_id`
     * still points at the now-staff-only `users` table and is no longer
     * populated by new signups (see CustomerAccountService::register()).
     * Self-service controllers should use findById() with a
     * CustomerAccount's own `stakeholder_id` instead. Left in place only
     * because a pre-split test row might still reference it locally —
     * remove once nothing calls this.
     */
    public function findByUserId(int $userId): ?Customer
    {
        return Customer::where('user_id', $userId)->where('is_customer_role', true)->first();
    }

    /**
     * Self-service lookup by a CustomerAccount's own `stakeholder_id` —
     * the customer/staff split's replacement for findByUserId(). Nullable:
     * a CustomerAccount that hasn't completed registration/profile setup
     * (e.g. a fresh Google sign-up) has no stakeholder_id yet.
     */
    public function findById(int $id): ?Customer
    {
        return Customer::where('id', $id)->where('is_customer_role', true)->first();
    }

    /**
     * Dashboard's `recentCustomers` list — latest N customer records.
     * DashboardController wraps this with CustomerResource, same one GET
     * /admin/customers uses.
     *
     * @return Collection<int, Customer>
     */
    public function recent(int $limit = 5): Collection
    {
        return Customer::query()->where('is_customer_role', true)->latest()->limit($limit)->get();
    }
}
