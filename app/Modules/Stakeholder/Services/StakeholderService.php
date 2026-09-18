<?php

namespace App\Modules\Stakeholder\Services;

use App\Modules\Stakeholder\Models\Stakeholder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Phase C of the Stakeholder merge (see Zurie_V3_ProsERP_Adaptation_Plan.md)
 * is now fully cut over: Customer and Supplier are mapped onto this same
 * `stakeholders` table (see their models' docblocks), so
 * orders.stakeholder_id/purchases.stakeholder_id are set directly from
 * $customer->id/$supplier->id — no separate mirroring step exists or is
 * needed any more. This service is now purely a read model over the
 * unified table (`GET /admin/stakeholders`, `GET /admin/stakeholders/{id}`)
 * — a merged view across both roles, distinct from the role-scoped
 * Customer/Supplier admin screens which stay in place.
 */
class StakeholderService
{
    public function all(): LengthAwarePaginator
    {
        return Stakeholder::query()->orderByDesc('id')->paginate(20);
    }

    public function findOrFail(int $id): Stakeholder
    {
        return Stakeholder::query()->findOrFail($id);
    }
}
