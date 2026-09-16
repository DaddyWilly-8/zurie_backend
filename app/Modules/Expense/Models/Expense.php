<?php

namespace App\Modules\Expense\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['category', 'amount', 'payment_method', 'description', 'cost_center_id', 'created_by'])]
class Expense extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }
}
