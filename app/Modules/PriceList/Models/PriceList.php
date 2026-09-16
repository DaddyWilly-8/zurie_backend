<?php

namespace App\Modules\PriceList\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_default', 'outlet_id', 'customer_id', 'valid_from', 'valid_to', 'is_active'])]
class PriceList extends Model
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }
}
