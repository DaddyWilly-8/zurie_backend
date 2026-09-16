<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\CostCenter;
use Illuminate\Database\Eloquent\Collection;

/**
 * Separate from FinanceService — that one owns ledger/journal posting,
 * this one owns the Cost Center dimension used to tag journal lines
 * (Zurie_V2_Architecture_Design (2).md §31). Kept as its own service so
 * FinanceService doesn't grow unrelated CRUD responsibilities.
 */
class CostCenterService
{
    /**
     * @param  array<string, mixed>  $data  name, parentId?
     */
    public function create(array $data): CostCenter
    {
        // Explicit default, not left to the DB column default — Eloquent's
        // create() doesn't reload DB-applied defaults into the in-memory
        // model, so relying on the schema default alone would return a
        // resource with isActive: null even though the DB stored true.
        // Translated from camelCase (parentId) to the column name
        // (parent_id) explicitly rather than array_merge()-ing $data in
        // raw — see OutletService::create()'s comment for why a raw merge
        // is the wrong pattern here.
        return CostCenter::create([
            'is_active' => true,
            'name' => $data['name'],
            'parent_id' => $data['parentId'] ?? null,
        ]);
    }

    public function all(): Collection
    {
        return CostCenter::query()->orderBy('name')->get();
    }

    public function findOrFail(int $id): CostCenter
    {
        return CostCenter::query()->findOrFail($id);
    }
}
