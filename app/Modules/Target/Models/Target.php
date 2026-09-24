<?php

namespace App\Modules\Target\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['period', 'target_amount'])]
class Target extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
        ];
    }

    /**
     * Audit trail (Admin > Activity): who created/changed/deleted this
     * and which fields changed.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('target')
            ->logOnly(['period', 'target_amount'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "Target #{$this->id} {$event}");
    }
}
