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
        $supplier = Supplier::create($data);

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
}
