<?php

namespace App\Modules\Customer\Services;

use App\Modules\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

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
        if ($userId !== null) {
            return Customer::firstOrCreate(
                ['user_id' => $userId],
                [
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'whatsapp_number' => $data['whatsapp_number'] ?? null,
                    'email' => $data['email'] ?? null,
                ]
            );
        }

        return Customer::firstOrCreate(
            ['phone' => $data['phone']],
            [
                'name' => $data['name'],
                'whatsapp_number' => $data['whatsapp_number'] ?? null,
                'email' => $data['email'] ?? null,
            ]
        );
    }

    public function paginateAdmin(int $page, int $pageSize): LengthAwarePaginator
    {
        return Customer::query()
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findForAdmin(int $id): Customer
    {
        return Customer::query()->findOrFail($id);
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
            return $existing;
        }

        $guestMatch = Customer::where('phone', $data['phone'])->whereNull('user_id')->first();
        if ($guestMatch !== null) {
            $guestMatch->update(['user_id' => $userId, 'name' => $data['name']]);

            return $guestMatch;
        }

        return Customer::create([
            'user_id' => $userId,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'email' => $data['email'] ?? null,
        ]);
    }

    public function findByUserId(int $userId): ?Customer
    {
        return Customer::where('user_id', $userId)->first();
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
        return Customer::query()->latest()->limit($limit)->get();
    }
}
