<?php

namespace App\Modules\Coupon\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'code', 'type', 'value', 'min_order_amount',
    'max_uses', 'used_count', 'valid_from', 'valid_to', 'is_active',
])]
class Coupon extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('coupon')
            ->logOnly(['code', 'value', 'is_active', 'used_count'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "Coupon '{$this->code}' {$event}");
    }
}
