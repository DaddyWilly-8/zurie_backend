<?php

namespace App\Modules\Outlet\Services;

use App\Modules\Outlet\Models\SalesOutlet;
use Illuminate\Database\Eloquent\Collection;

/**
 * Any future module needing outlet data (Order/POS in Phase 3) depends on
 * this Service, never queries `sales_outlets` directly — see the
 * Extensibility Constitution, Rule 2.
 */
class OutletService
{
    /**
     * @param  array<string, mixed>  $data  name, type, address?, costCenterId?
     */
    public function create(array $data): SalesOutlet
    {
        // Same explicit-default reasoning as CostCenterService::create().
        // Translated from the request's camelCase keys to the model's
        // snake_case fillable/column names explicitly, rather than
        // array_merge()-ing $data straight in — a raw merge silently drops
        // any key that doesn't already match a fillable column name, which
        // is exactly the bug this replaced (StoreSalesOutletRequest used
        // to validate 'cost_center_id', breaking the app-wide camelCase
        // JSON convention every other endpoint follows).
        return SalesOutlet::create([
            'is_active' => true,
            'name' => $data['name'],
            'type' => $data['type'] ?? null,
            'address' => $data['address'] ?? null,
            'cost_center_id' => $data['costCenterId'] ?? null,
        ]);
    }

    public function allActive(): Collection
    {
        return SalesOutlet::query()->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * Every outlet regardless of is_active — for the admin list, which
     * needs to show (and let an admin reactivate) deactivated outlets too,
     * unlike allActive()'s callers (Order/POS, which should never let a
     * checkout resolve to a deactivated outlet).
     */
    public function all(): Collection
    {
        return SalesOutlet::query()->orderBy('name')->get();
    }

    public function findOrFail(int $id): SalesOutlet
    {
        return SalesOutlet::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data  name?, type?, address?, costCenterId?
     */
    public function update(SalesOutlet $outlet, array $data): SalesOutlet
    {
        $outlet->update([
            'name' => $data['name'] ?? $outlet->name,
            'type' => $data['type'] ?? $outlet->type,
            'address' => array_key_exists('address', $data) ? $data['address'] : $outlet->address,
            'cost_center_id' => array_key_exists('costCenterId', $data) ? $data['costCenterId'] : $outlet->cost_center_id,
        ]);

        return $outlet;
    }

    /**
     * "Delete" deactivates rather than removing the row — a hard delete
     * would orphan every historical Order.outlet_id pointing at this
     * outlet. Reactivation is the same call with isActive: true.
     */
    public function setActive(SalesOutlet $outlet, bool $isActive): SalesOutlet
    {
        $outlet->update(['is_active' => $isActive]);

        return $outlet;
    }

    /**
     * The one outlet every existing website order resolves to — seeded at
     * install (OutletSeeder) so Phase 3's `orders.outlet_id` never needs a
     * nullable column or a migration of historical rows.
     */
    public function defaultOnlineOutlet(): SalesOutlet
    {
        return SalesOutlet::query()->where('type', 'online')->firstOrFail();
    }
}
