<?php

namespace App\Modules\Customer\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'name', 'phone', 'whatsapp_number', 'email', 'is_active', 'is_customer_role'])]
class Customer extends Model
{
    // Phase C (Stakeholder merge, see Zurie_V3_ProsERP_Adaptation_Plan.md)
    // — this table is now `stakeholders`, the same physical table
    // Supplier is also mapped onto. One row per real-world entity; a
    // stakeholder can be a customer, a supplier, or both on the same
    // row, matching the reference doc's own philosophy that role is a
    // fact about how a stakeholder is *used*, not a separate record.
    // `is_customer_role` is what keeps this module's own queries
    // (paginateAdmin() etc.) scoped to rows actually used as a customer
    // — see CustomerService for where that's applied and why it's
    // necessary now that the table is shared.
    protected $table = 'stakeholders';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_customer_role' => 'boolean',
        ];
    }

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
