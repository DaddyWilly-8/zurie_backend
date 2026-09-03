<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['product_id', 'quantity', 'stock_status'])]
class Inventory extends Model
{
    protected $table = 'inventory';

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    // Deliberately no relation/FK back to Product — product_id is a
    // cross-module reference (no FK constraint). Product's identity is
    // validated by calling Product's own service at write time, not by an
    // Eloquent relationship into another module's table.
}
