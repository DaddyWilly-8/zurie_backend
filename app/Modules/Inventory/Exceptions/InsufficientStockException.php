<?php

namespace App\Modules\Inventory\Exceptions;

use Exception;

/**
 * Thrown by InventoryService::decrementForOrder() when a checkout line
 * would take stock below zero. Caught globally in bootstrap/app.php's
 * exception handler and turned into a 422 response — same "expected,
 * preventable user action, not a real server error" treatment already
 * given to FK-conflict QueryExceptions there.
 */
class InsufficientStockException extends Exception
{
    public function __construct(
        public readonly int $productId,
        public readonly int $available,
        public readonly int $requested,
    ) {
        parent::__construct(
            "Insufficient stock for product #{$productId}: requested {$requested}, only {$available} available."
        );
    }
}
