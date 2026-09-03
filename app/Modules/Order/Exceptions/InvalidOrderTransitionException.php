<?php

namespace App\Modules\Order\Exceptions;

use Exception;

/**
 * Thrown by OrderService when a requested change would violate an order
 * status transition rule:
 *  - cancel() called on an order whose current status isn't cancellable
 *    (only new/confirmed/processing/ready_for_delivery are — not
 *    delivered, and not an already-cancelled order)
 *  - updateStatusOrNotes() called with a status change on an order that's
 *    already cancelled — cancelled is a terminal state; nothing moves out
 *    of it via the generic PATCH endpoint
 *
 * Caught globally in bootstrap/app.php and turned into a 422 — an expected,
 * preventable admin action, not a real server error.
 */
class InvalidOrderTransitionException extends Exception {}
