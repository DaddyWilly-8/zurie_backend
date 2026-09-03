<?php

namespace App\Modules\Customer\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'phone', 'whatsapp_number', 'email'])]
class Customer extends Model
{
    protected $table = 'customers';

    // Deliberately no relation to Order — order_id/customer_id crosses a
    // module boundary. Order stores customer_id with no FK and snapshots
    // customer_name/customer_phone/etc. at order time; it never needs an
    // Eloquent relationship back into this table.
}
