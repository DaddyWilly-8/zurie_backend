<?php

namespace App\Modules\Target\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['period', 'target_amount'])]
class Target extends Model
{
    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
        ];
    }
}
