<?php

namespace App\Modules\Customer\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'name', 'phone', 'whatsapp_number', 'email'])]
class Customer extends Model
{
    protected $table = 'customers';

    // Deliberately no relation to Order — order_id/customer_id crosses a
    // module boundary. Order stores customer_id with no FK and snapshots
    // customer_name/customer_phone/etc. at order time; it never needs an
    // Eloquent relationship back into this table.
    //
    // user_id DOES have a real FK (see migration) — unlike Order, this is
    // companion/reference data (a customer's login link is structural, not
    // a point-in-time snapshot), same reasoning as SalesOutlet<->CostCenter.
    // No belongsTo(User::class) relation defined here though — Auth is a
    // separate module and this stays a plain foreign key value, consistent
    // with "cross-module coupling via Services only" (Extensibility
    // Constitution, Rule 2); nothing in this module needs to load the
    // related User record via Eloquent.
}
