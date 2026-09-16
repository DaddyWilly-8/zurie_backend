<?php

namespace App\Modules\Outlet\Models;

use App\Modules\Finance\Models\CostCenter;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'type', 'address', 'cost_center_id', 'is_active'])]
class SalesOutlet extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Cross-module FK to Finance's CostCenter — a real FK (unlike Order's
     * deliberate no-FK-to-Customer pattern) since Outlet<->CostCenter is
     * companion/reference data, not a point-in-time transactional snapshot.
     */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id');
    }
}
