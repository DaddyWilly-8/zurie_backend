<?php

namespace App\Modules\Outlet\Models;

use App\Modules\Finance\Models\CostCenter;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['name', 'type', 'address', 'cost_center_id', 'is_active'])]
class SalesOutlet extends Model
{
    use LogsActivity;

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

    /**
     * Audit trail (Admin > Activity): who created/changed/deleted this
     * and which fields changed.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('outlet')
            ->logOnly(['name', 'type', 'address', 'cost_center_id', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "Outlet '{$this->name}' {$event}");
    }
}
