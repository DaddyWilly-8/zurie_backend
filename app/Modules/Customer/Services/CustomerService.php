<?php

namespace App\Modules\Customer\Services;

use App\Modules\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class CustomerService
{
    /**
     * Dedupe by phone, DB-unique-indexed. Called by Order's checkout flow
     * (not built yet) — there is intentionally no POST /customers; a
     * customer record is only ever created as a side effect of order
     * placement, per zurie-backend-implementation-spec.md §6.
     *
     * @param  array<string, mixed>  $data  name, phone, whatsapp_number?, email?
     */
    public function findOrCreate(array $data): Customer
    {
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
