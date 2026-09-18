<?php

namespace App\Modules\Stakeholder\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id',
    'name',
    'phone',
    'type',
    'tin',
    'vrn',
    'address',
    'email',
    'website',
    'remarks',
    'whatsapp_number',
    'is_active',
    'is_customer_role',
    'is_supplier_role',
])]
class Stakeholder extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_customer_role' => 'boolean',
            'is_supplier_role' => 'boolean',
        ];
    }
}
