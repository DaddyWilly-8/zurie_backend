<?php

namespace App\Modules\Supplier\Services;

use App\Modules\Finance\Services\FinanceService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SupplierService
{
    public function __construct(private readonly FinanceService $financeService) {}

    /**
     * Every Supplier gets its payable ledger created immediately, not
     * lazily on first purchase — so PurchaseService can always assume one
     * exists (FinanceService::ledgerFor() fails loudly instead of silently
     * auto-creating one, which would mask a bug). See
     * Zurie_V2_Architecture_Design (2).md §30.1 — Sundry Creditors.
     *
     * @param  array<string, mixed>  $data  name, phone?, email?, address?
     */
    public function create(array $data): Supplier
    {
        // is_active set explicitly rather than left to the DB column
        // default — Eloquent's create() doesn't reload DB-applied defaults
        // into the in-memory model (same bug class as CostCenterService::
        // create() had — see that fix's commit).
        $supplier = Supplier::create([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'is_active' => true,
        ]);

        $sundryCreditors = $this->financeService->ledgerGroupByCode('CRED');
        $this->financeService->findOrCreateLedgerFor($supplier, $sundryCreditors, $supplier->name);

        return $supplier;
    }

    public function paginateAdmin(int $page, int $pageSize): LengthAwarePaginator
    {
        return Supplier::query()->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findOrFail(int $id): Supplier
    {
        return Supplier::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data  name?, phone?, email?, address?
     */
    public function update(Supplier $supplier, array $data): Supplier
    {
        $supplier->update([
            'name' => $data['name'] ?? $supplier->name,
            'phone' => array_key_exists('phone', $data) ? $data['phone'] : $supplier->phone,
            'email' => array_key_exists('email', $data) ? $data['email'] : $supplier->email,
            'address' => array_key_exists('address', $data) ? $data['address'] : $supplier->address,
        ]);

        return $supplier;
    }

    /**
     * "Delete" deactivates rather than removing the row — a hard delete
     * would orphan every historical Purchase.supplier_id pointing at this
     * supplier, and the supplier's own payable ledger (Ledger.reference_id)
     * created in create() above. Reactivation is the same call with
     * isActive: true.
     */
    public function setActive(Supplier $supplier, bool $isActive): Supplier
    {
        $supplier->update(['is_active' => $isActive]);

        return $supplier;
    }
}
