<?php

namespace App\Modules\Procurement\Requests;

class UpdatePurchaseOrderRequest extends StorePurchaseOrderRequest
{
    // Same shape as create — update() is a full delete+recreate of items,
    // never a partial diff (see PurchaseOrderService::update()'s docblock).
}
